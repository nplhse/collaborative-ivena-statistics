<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Repository;

use App\Encounter\Application\Contract\MatchValidationReaderInterface;
use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Entity\MatchValidationItem;
use App\Encounter\Domain\Entity\MatchValidationSample;
use App\Encounter\Domain\Enum\ValidationStratum;
use App\Encounter\Domain\Enum\ValidationVerdict;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Samples and items of the match validation (#1057).
 *
 * @psalm-suppress UnusedClass Autowired as MatchValidationReaderInterface.
 */
final readonly class MatchValidationRepository implements MatchValidationReaderInterface
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function samples(int $hospitalId): array
    {
        /* @var list<MatchValidationSample> */
        return $this->entityManager->getRepository(MatchValidationSample::class)->findBy(['hospital' => $hospitalId], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    #[\Override]
    public function sample(int $hospitalId, int $sampleId): ?MatchValidationSample
    {
        return $this->entityManager->getRepository(MatchValidationSample::class)->findOneBy(['id' => $sampleId, 'hospital' => $hospitalId]);
    }

    #[\Override]
    public function items(MatchValidationSample $sample): array
    {
        /* @var list<MatchValidationItem> */
        return $this->entityManager->getRepository(MatchValidationItem::class)->findBy(['sample' => $sample], ['position' => 'ASC']);
    }

    #[\Override]
    public function item(MatchValidationSample $sample, int $itemId): ?MatchValidationItem
    {
        return $this->entityManager->getRepository(MatchValidationItem::class)->findOneBy(['id' => $itemId, 'sample' => $sample]);
    }

    #[\Override]
    public function itemsByMatcherVersion(int $hospitalId): array
    {
        /** @var list<MatchValidationItem> $items */
        $items = $this->entityManager->createQueryBuilder()
            ->select('i', 's')
            ->from(MatchValidationItem::class, 'i')
            ->join('i.sample', 's')
            ->where('IDENTITY(s.hospital) = :hospital')
            ->setParameter('hospital', $hospitalId)
            ->orderBy('s.id')
            ->addOrderBy('i.position')
            ->getQuery()
            ->getResult();

        $byVersion = [];
        foreach ($items as $item) {
            $byVersion[$item->getSample()->getMatcherVersion()][] = [
                'stratum' => $item->getStratum(),
                'band' => $item->getBand(),
                'verdict' => $item->getVerdict(),
                'replaced' => $item->getReplacementEncounter() instanceof Encounter,
            ];
        }

        return $byVersion;
    }

    #[\Override]
    public function pairItemsByPlausibility(int $hospitalId): array
    {
        /** @var list<MatchValidationItem> $items */
        $items = $this->entityManager->createQueryBuilder()
            ->select('i', 's')
            ->from(MatchValidationItem::class, 'i')
            ->join('i.sample', 's')
            ->where('IDENTITY(s.hospital) = :hospital')
            ->andWhere('i.stratum IN (:pairs)')
            ->setParameter('hospital', $hospitalId)
            ->setParameter('pairs', [ValidationStratum::Automatic->value, ValidationStratum::Proposal->value])
            ->orderBy('s.id')
            ->addOrderBy('i.position')
            ->getQuery()
            ->getResult();

        return array_map(static fn (MatchValidationItem $item): array => [
            'stratum' => $item->getStratum(),
            'matcher_version' => $item->getMatcherVersion() ?? $item->getSample()->getMatcherVersion(),
            'snapshot_id' => $item->getPlausibilitySnapshot()?->getId(),
            'decisive' => $item->isPlausibilityDecisive(),
            'verdict' => $item->getVerdict(),
            'replaced' => $item->getReplacementEncounter() instanceof Encounter,
        ], $items);
    }

    #[\Override]
    public function pairVerdicts(int $hospitalId): array
    {
        /** @var list<array{allocation_id: int|string, encounter_id: int|string, verdict: string}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            <<<'SQL'
SELECT i.allocation_id, i.encounter_id, i.verdict
FROM encounter_validation_item i
JOIN encounter_validation_sample s ON s.id = i.sample_id
WHERE s.hospital_id = :hospital AND i.allocation_id IS NOT NULL AND i.encounter_id IS NOT NULL AND i.verdict IN ('correct', 'wrong')
ORDER BY i.decided_at, i.id
SQL,
            ['hospital' => $hospitalId],
            ['hospital' => Types::INTEGER],
        );

        $verdicts = [];
        foreach ($rows as $row) {
            $verdicts[$row['allocation_id'].':'.$row['encounter_id']] = ValidationVerdict::Correct->value === $row['verdict'];
        }

        return $verdicts;
    }

    #[\Override]
    public function encounterIdsByFingerprint(int $hospitalId, array $fingerprints): array
    {
        if ([] === $fingerprints) {
            return [];
        }

        /** @var list<array{id: int|string, visit_fingerprint: string}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT id, visit_fingerprint FROM encounter WHERE hospital_id = :hospital AND visit_fingerprint IN (:fingerprints)',
            ['hospital' => $hospitalId, 'fingerprints' => $fingerprints],
            ['hospital' => Types::INTEGER, 'fingerprints' => ArrayParameterType::STRING],
        );

        $ids = [];
        foreach ($rows as $row) {
            $ids[$row['visit_fingerprint']] = (int) $row['id'];
        }

        return $ids;
    }
}
