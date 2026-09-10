<?php

declare(strict_types=1);

namespace App\Import\Application\ReferenceCatalog;

use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogDocument;
use App\Allocation\Domain\Entity\Assignment;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Domain\Entity\Occasion;
use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Allocation\Domain\Entity\Speciality;
use App\Import\Application\Analysis\ImportRejectAnalysisReader;
use App\Import\Application\Analysis\RejectMessageNormalizer;
use App\Import\Application\Mapping\Cp850MojibakeRepair;
use App\Import\Application\Mapping\DepartmentNameAlias;
use App\Import\Application\Mapping\DispatchAreaNameNormalizer;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ReferenceCatalogFromRejectsProposer
{
    private const array OCCASION_DENYLIST = [
        'erhängen',
    ];

    private const array INFECTION_NULL_VALUES = [
        'keine',
        'k.a.',
        'k. a.',
    ];

    public function __construct(
        private ImportRejectAnalysisReader $importRejectRepository,
        private RejectMessageNormalizer $messageNormalizer,
        private DispatchAreaNameNormalizer $dispatchAreaNameNormalizer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function propose(int $minCount = 1): ReferenceCatalogFromRejectsResult
    {
        $known = $this->loadKnownLookupKeys();
        $buckets = [];
        $importMeta = [];
        $scanned = 0;
        $droppedKnown = 0;
        $droppedJunk = 0;

        foreach ($this->importRejectRepository->iterateForAnalysis() as $reject) {
            ++$scanned;
            $candidates = $this->candidatesFromReject($reject['messages'], $reject['row']);
            $proposedThisRow = false;

            foreach ($candidates as $candidate) {
                if ($this->isJunk($candidate['type'], $candidate['value'])) {
                    ++$droppedJunk;
                    continue;
                }

                $canonical = $this->canonicalize($candidate['type'], $candidate['value']);
                if (null === $canonical || Cp850MojibakeRepair::hasC1Controls($canonical)) {
                    ++$droppedJunk;
                    continue;
                }

                if ($this->isKnown($known, $candidate['type'], $canonical)) {
                    ++$droppedKnown;
                    continue;
                }

                $key = $candidate['type']."\0".$canonical;
                if (!isset($buckets[$key])) {
                    $buckets[$key] = [
                        'type' => $candidate['type'],
                        'value' => $canonical,
                        'count' => 0,
                        'exampleFile' => $reject['importFilePath'] ?? $reject['importName'] ?? '',
                    ];
                }
                ++$buckets[$key]['count'];
                $proposedThisRow = true;
            }

            if ($proposedThisRow && null !== $reject['importId']) {
                $importId = $reject['importId'];
                if (!isset($importMeta[$importId])) {
                    $importMeta[$importId] = [
                        'importId' => $importId,
                        'hospitalName' => $reject['hospitalName'] ?? '',
                        'file' => $reject['importFilePath'] ?? $reject['importName'] ?? '',
                        'count' => 0,
                    ];
                }
                ++$importMeta[$importId]['count'];
            }
        }

        $entries = array_values(array_filter(
            $buckets,
            static fn (array $entry): bool => $entry['count'] >= $minCount,
        ));
        usort(
            $entries,
            static fn (array $left, array $right): int => $right['count'] <=> $left['count']
                ?: $left['type'] <=> $right['type']
                ?: $left['value'] <=> $right['value'],
        );

        ksort($importMeta);

        return new ReferenceCatalogFromRejectsResult(
            document: $this->documentFromEntries($entries),
            importIds: array_map(static fn (array $row): int => $row['importId'], array_values($importMeta)),
            imports: array_values($importMeta),
            entries: $entries,
            rejectRowsScanned: $scanned,
            proposedCount: \count($entries),
            droppedAsKnown: $droppedKnown,
            droppedAsJunk: $droppedJunk,
        );
    }

    /**
     * @param list<string>         $messages
     * @param array<string, mixed> $row
     *
     * @return list<array{type: string, value: string}>
     */
    private function candidatesFromReject(array $messages, array $row): array
    {
        $candidates = [];
        foreach ($messages as $message) {
            $normalized = $this->messageNormalizer->normalize($message, $row);
            if (!str_contains($normalized['reason'], 'REF_NOT_FOUND')) {
                continue;
            }

            $type = $this->catalogTypeForField($normalized['field']);
            if (null === $type) {
                continue;
            }

            $value = trim($normalized['rejected_value']);
            if ('' === $value || '(empty)' === $value) {
                continue;
            }

            $candidates[] = ['type' => $type, 'value' => $value];
        }

        $softFields = [
            'occasion' => 'anlass',
            'infection' => 'ansteckungsfaehig',
            'secondary-transport' => 'sekundaeranlass',
        ];
        foreach ($softFields as $type => $column) {
            if (!\array_key_exists($column, $row)) {
                continue;
            }

            $value = trim((string) $row[$column]);
            if ('' === $value) {
                continue;
            }

            $candidates[] = ['type' => $type, 'value' => $value];
        }

        return $candidates;
    }

    private function catalogTypeForField(string $field): ?string
    {
        return match ($field) {
            'dispatchArea' => 'dispatch-area',
            'department' => 'department',
            'speciality' => 'speciality',
            'assignment' => 'assignment',
            'occasion' => 'occasion',
            'infection' => 'infection',
            'secondaryTransport' => 'secondary-transport',
            default => null,
        };
    }

    private function canonicalize(string $type, string $value): ?string
    {
        $value = Cp850MojibakeRepair::repair(trim($value));
        if ('dispatch-area' === $type) {
            return $this->dispatchAreaNameNormalizer->normalize($value);
        }

        return '' === $value ? null : $value;
    }

    /**
     * @param array<string, array<string, true>> $known
     */
    private function isKnown(array $known, string $type, string $value): bool
    {
        $key = $this->lookupKey($value);
        if ('department' === $type) {
            $key = DepartmentNameAlias::canonicalKey($key);
        }

        return isset($known[$type][$key]);
    }

    private function isJunk(string $type, string $value): bool
    {
        $trimmed = trim($value);
        if ('' === $trimmed) {
            return true;
        }

        if (preg_match('#https?://#i', $trimmed)) {
            return true;
        }

        if (str_contains($trimmed, "\u{FFFD}") || preg_match('/Ã.|Â./u', $trimmed)) {
            return true;
        }

        $key = $this->lookupKey($trimmed);
        if ('occasion' === $type && \in_array($key, self::OCCASION_DENYLIST, true)) {
            return true;
        }

        return 'infection' === $type && \in_array($key, self::INFECTION_NULL_VALUES, true);
    }

    /**
     * @param list<array{type: string, value: string, count: int, exampleFile: string}> $entries
     */
    private function documentFromEntries(array $entries): ReferenceCatalogDocument
    {
        $document = new ReferenceCatalogDocument();
        $seen = [];

        foreach ($entries as $entry) {
            $dedupe = $entry['type']."\0".$entry['value'];
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;

            match ($entry['type']) {
                'dispatch-area' => $document->dispatchAreas[] = ['name' => $entry['value'], 'state' => null],
                'department' => $document->departments[] = $entry['value'],
                'speciality' => $document->specialities[] = $entry['value'],
                'assignment' => $document->assignments[] = $entry['value'],
                'occasion' => $document->occasions[] = $entry['value'],
                'infection' => $document->infections[] = $entry['value'],
                'secondary-transport' => $document->secondaryTransports[] = $entry['value'],
                default => null,
            };
        }

        return $document;
    }

    /**
     * @return array<string, array<string, true>>
     */
    private function loadKnownLookupKeys(): array
    {
        return [
            'dispatch-area' => $this->nameKeys(DispatchArea::class),
            'department' => $this->nameKeys(Department::class),
            'speciality' => $this->nameKeys(Speciality::class),
            'assignment' => $this->nameKeys(Assignment::class),
            'occasion' => $this->nameKeys(Occasion::class),
            'infection' => $this->nameKeys(Infection::class),
            'secondary-transport' => $this->nameKeys(SecondaryTransport::class),
        ];
    }

    /**
     * @param class-string $class
     *
     * @return array<string, true>
     */
    private function nameKeys(string $class): array
    {
        $keys = [];
        foreach ($this->entityManager->getRepository($class)->findBy([]) as $entity) {
            if (!method_exists($entity, 'getName')) {
                continue;
            }

            $keys[$this->lookupKey((string) $entity->getName())] = true;
        }

        return $keys;
    }

    private function lookupKey(string $name): string
    {
        $s = mb_strtolower(trim($name), 'UTF-8');
        $normalized = preg_replace('/\s+/', ' ', $s);

        return $normalized ?? $s;
    }
}
