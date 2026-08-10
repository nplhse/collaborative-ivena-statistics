<?php

declare(strict_types=1);

namespace App\Allocation\Application\Export;

use App\Allocation\Application\Export\DTO\OwnHospitalAllocationsExportFilter;
use App\Allocation\Infrastructure\Query\OwnHospitalAllocationsExportQuery;
use App\Shared\Application\Export\CsvStreamExportResponseFactory;
use App\Shared\Application\Export\ExporterInterface;
use App\User\Domain\Entity\User;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class OwnHospitalAllocationsExporter implements ExporterInterface
{
    public const string KEY = 'own_hospital_allocations';

    /**
     * Technical column keys (stable order). Labels are resolved via {@see CSV_HEADER_TRANSLATIONS}.
     *
     * @var list<string>
     */
    private const array BASE_CSV_HEADERS = [
        'row',
        'arrivalAt',
        'createdAt',
        'hospital',
        'state',
        'dispatchArea',
        'gender',
        'age',
        'urgency',
        'transportType',
        'indicationNormalized',
    ];

    /**
     * @var list<string>
     */
    private const array TAIL_CSV_HEADERS = [
        'secondaryTransport',
        'department',
        'speciality',
        'departmentWasClosed',
        'assignment',
        'occasion',
        'requiresResus',
        'requiresCathlab',
        'isCPR',
        'isVentilated',
        'isShock',
        'isPregnant',
        'isWorkAccident',
        'isWithPhysician',
        'infection',
    ];

    /**
     * @var array<string, array{0: string, 1: string}>
     */
    private const array CSV_HEADER_TRANSLATIONS = [
        'row' => ['export.column.row', 'allocation'],
        'arrivalAt' => ['label.arrival_at', 'messages'],
        'createdAt' => ['label.created_at', 'messages'],
        'hospital' => ['label.hospital', 'messages'],
        'state' => ['label.state', 'messages'],
        'dispatchArea' => ['label.dispatch_area', 'messages'],
        'gender' => ['field.gender', 'messages'],
        'age' => ['field.age', 'messages'],
        'urgency' => ['field.urgency', 'messages'],
        'transportType' => ['field.transportType', 'messages'],
        'indicationNormalized' => ['label.indication.normalized', 'messages'],
        'indicationRaw' => ['label.indication.raw', 'messages'],
        'secondaryTransport' => ['label.secondary_transport', 'messages'],
        'department' => ['label.department', 'messages'],
        'speciality' => ['label.speciality', 'messages'],
        'departmentWasClosed' => ['field.departmentWasClosed', 'messages'],
        'assignment' => ['label.assignment', 'messages'],
        'occasion' => ['label.occasion', 'messages'],
        'requiresResus' => ['field.requiresResus', 'messages'],
        'requiresCathlab' => ['field.requiresCathlab', 'messages'],
        'isCPR' => ['field.isCPR', 'messages'],
        'isVentilated' => ['allocations.field.isVentilated', 'allocation'],
        'isShock' => ['allocations.field.isShock', 'allocation'],
        'isPregnant' => ['allocations.field.isPregnant', 'allocation'],
        'isWorkAccident' => ['allocations.field.isWorkAccident', 'allocation'],
        'isWithPhysician' => ['field.isWithPhysician', 'messages'],
        'infection' => ['label.infection', 'messages'],
    ];

    public function __construct(
        private ExportAccessService $exportAccessService,
        private OwnHospitalAllocationsExportQuery $exportQuery,
        private CsvStreamExportResponseFactory $csvStreamExportResponseFactory,
        private AllocationExportValueFormatter $exportValueFormatter,
        private TranslatorInterface $translator,
        private RequestStack $requestStack,
    ) {
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function assertCanExport(User $user): void
    {
        if (!$this->exportAccessService->canExport($user)) {
            throw new AccessDeniedException('Export is not allowed for this user.');
        }
    }

    #[\Override]
    public function resolveScopeHospitalIds(User $user): array
    {
        return $this->exportAccessService->resolveExportHospitalIds($user);
    }

    #[\Override]
    public function count(User $user, object $criteria): int
    {
        $filter = $this->assertFilter($criteria);

        return $this->exportQuery->count(
            $this->resolveHospitalIdsForExport($user, $filter),
            $filter,
        );
    }

    #[\Override]
    public function writeCsv(User $user, object $criteria, $stream): int
    {
        $filter = $this->assertFilter($criteria);
        $locale = $this->resolveLocale();
        $this->csvStreamExportResponseFactory->writeRow($stream, $this->resolveCsvHeaders($filter, $locale));

        $written = 0;
        foreach ($this->exportQuery->iterateRows($this->resolveHospitalIdsForExport($user, $filter), $filter) as $row) {
            ++$written;
            $this->csvStreamExportResponseFactory->writeRow($stream, $this->formatRow($written, $row, $filter, $locale));
        }

        return $written;
    }

    #[\Override]
    public function buildFilename(): string
    {
        return sprintf('allocations-export-%s.csv', new \DateTimeImmutable('now')->format('Y-m-d'));
    }

    #[\Override]
    public function serializeCriteria(object $criteria): array
    {
        return $this->assertFilter($criteria)->toAuditArray();
    }

    private function assertFilter(object $criteria): OwnHospitalAllocationsExportFilter
    {
        if (!$criteria instanceof OwnHospitalAllocationsExportFilter) {
            throw new \InvalidArgumentException(sprintf('Expected %s, got %s.', OwnHospitalAllocationsExportFilter::class, $criteria::class));
        }

        return $criteria;
    }

    /**
     * @return list<int>
     */
    private function resolveHospitalIdsForExport(User $user, OwnHospitalAllocationsExportFilter $filter): array
    {
        return $this->exportAccessService->resolveEffectiveHospitalIds($user, $filter->hospitalIds);
    }

    /**
     * @return list<string>
     */
    private function resolveCsvHeaders(OwnHospitalAllocationsExportFilter $filter, ?string $locale): array
    {
        $keys = self::BASE_CSV_HEADERS;
        if ($filter->includeIndicationRaw) {
            $keys[] = 'indicationRaw';
        }

        $keys = array_merge($keys, self::TAIL_CSV_HEADERS);

        return array_map(
            fn (string $key): string => $this->translateHeader($key, $locale),
            $keys,
        );
    }

    private function translateHeader(string $columnKey, ?string $locale): string
    {
        $mapping = self::CSV_HEADER_TRANSLATIONS[$columnKey] ?? null;
        if (null === $mapping) {
            return $columnKey;
        }

        [$translationKey, $domain] = $mapping;

        return $this->translator->trans($translationKey, [], $domain, $locale);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string|int|float|null>
     */
    private function formatRow(int $rowNumber, array $row, OwnHospitalAllocationsExportFilter $filter, ?string $locale): array
    {
        /** @var list<string|int|float|null> $values */
        $values = [
            $rowNumber,
            $this->formatDateTime($row['arrivalAt'] ?? null),
            $this->formatDateTime($row['createdAt'] ?? null),
            $this->scalarCell($row['hospital'] ?? null),
            $this->scalarCell($row['state'] ?? null),
            $this->scalarCell($row['dispatchArea'] ?? null),
            $this->exportValueFormatter->gender($row['gender'] ?? null, $locale),
            $this->scalarCell($row['age'] ?? null),
            $this->exportValueFormatter->urgency($row['urgency'] ?? null),
            $this->exportValueFormatter->transportType($row['transportType'] ?? null, $locale),
            $this->scalarCell($row['indicationNormalized'] ?? null),
        ];

        if ($filter->includeIndicationRaw) {
            $values[] = $this->scalarCell($row['indicationRaw'] ?? null);
        }

        return [
            ...$values,
            $this->scalarCell($row['secondaryTransport'] ?? null),
            $this->scalarCell($row['department'] ?? null),
            $this->scalarCell($row['speciality'] ?? null),
            $this->formatBool($row['departmentWasClosed'] ?? null, $locale),
            $this->scalarCell($row['assignment'] ?? null),
            $this->scalarCell($row['occasion'] ?? null),
            $this->formatBool($row['requiresResus'] ?? null, $locale),
            $this->formatBool($row['requiresCathlab'] ?? null, $locale),
            $this->formatBool($row['isCPR'] ?? null, $locale),
            $this->formatBool($row['isVentilated'] ?? null, $locale),
            $this->formatBool($row['isShock'] ?? null, $locale),
            $this->formatBool($row['isPregnant'] ?? null, $locale),
            $this->formatBool($row['isWorkAccident'] ?? null, $locale),
            $this->formatBool($row['isWithPhysician'] ?? null, $locale),
            $this->scalarCell($row['infection'] ?? null),
        ];
    }

    private function resolveLocale(): ?string
    {
        return $this->requestStack->getCurrentRequest()?->getLocale();
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (null === $value) {
            return null;
        }

        if (\is_string($value) || \is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    private function scalarCell(mixed $value): string|int|float|null
    {
        if (null === $value || \is_string($value) || \is_int($value) || \is_float($value)) {
            return $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return null;
    }

    private function formatBool(mixed $value, ?string $locale): ?string
    {
        if (null === $value) {
            return null;
        }

        $key = filter_var($value, FILTER_VALIDATE_BOOLEAN)
            ? 'export.boolean.true'
            : 'export.boolean.false';

        return $this->translator->trans($key, [], 'allocation', $locale);
    }
}
