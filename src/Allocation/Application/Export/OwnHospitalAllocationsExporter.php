<?php

declare(strict_types=1);

namespace App\Allocation\Application\Export;

use App\Allocation\Application\Export\DTO\OwnHospitalAllocationsExportFilter;
use App\Allocation\Infrastructure\Query\OwnHospitalAllocationsExportQuery;
use App\Shared\Application\Export\CsvStreamExportResponseFactory;
use App\Shared\Application\Export\ExporterInterface;
use App\User\Domain\Entity\User;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class OwnHospitalAllocationsExporter implements ExporterInterface
{
    public const string KEY = 'own_hospital_allocations';

    /**
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

    public function __construct(
        private ExportAccessService $exportAccessService,
        private OwnHospitalAllocationsExportQuery $exportQuery,
        private CsvStreamExportResponseFactory $csvStreamExportResponseFactory,
        private AllocationExportValueFormatter $exportValueFormatter,
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
        $this->csvStreamExportResponseFactory->writeRow($stream, $this->resolveCsvHeaders($filter));

        $written = 0;
        foreach ($this->exportQuery->iterateRows($this->resolveHospitalIdsForExport($user, $filter), $filter) as $row) {
            ++$written;
            $this->csvStreamExportResponseFactory->writeRow($stream, $this->formatRow($written, $row, $filter));
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
    private function resolveCsvHeaders(OwnHospitalAllocationsExportFilter $filter): array
    {
        $headers = self::BASE_CSV_HEADERS;
        if ($filter->includeIndicationRaw) {
            $headers[] = 'indicationRaw';
        }

        return array_merge($headers, self::TAIL_CSV_HEADERS);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string|int|float|null>
     */
    private function formatRow(int $rowNumber, array $row, OwnHospitalAllocationsExportFilter $filter): array
    {
        $values = [
            $rowNumber,
            $this->formatDateTime($row['arrivalAt'] ?? null),
            $this->formatDateTime($row['createdAt'] ?? null),
            $row['hospital'] ?? null,
            $row['state'] ?? null,
            $row['dispatchArea'] ?? null,
            $this->exportValueFormatter->gender($row['gender'] ?? null),
            $row['age'] ?? null,
            $this->exportValueFormatter->urgency($row['urgency'] ?? null),
            $this->exportValueFormatter->transportType($row['transportType'] ?? null),
            $row['indicationNormalized'] ?? null,
        ];

        if ($filter->includeIndicationRaw) {
            $values[] = $row['indicationRaw'] ?? null;
        }

        return array_merge($values, [
            $row['secondaryTransport'] ?? null,
            $row['department'] ?? null,
            $row['speciality'] ?? null,
            $this->formatBool($row['departmentWasClosed'] ?? null),
            $row['assignment'] ?? null,
            $row['occasion'] ?? null,
            $this->formatBool($row['requiresResus'] ?? null),
            $this->formatBool($row['requiresCathlab'] ?? null),
            $this->formatBool($row['isCPR'] ?? null),
            $this->formatBool($row['isVentilated'] ?? null),
            $this->formatBool($row['isShock'] ?? null),
            $this->formatBool($row['isPregnant'] ?? null),
            $this->formatBool($row['isWorkAccident'] ?? null),
            $this->formatBool($row['isWithPhysician'] ?? null),
            $row['infection'] ?? null,
        ]);
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return null === $value ? null : (string) $value;
    }

    private function formatBool(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
    }
}
