<?php

declare(strict_types=1);

namespace App\Kpi\Application\Service;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Kpi\Domain\Entity\KpiDaily;
use App\Kpi\Infrastructure\Query\KpiDayAggregationQuery;
use App\Kpi\Infrastructure\Repository\KpiDailyRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class KpiAggregationService
{
    public function __construct(
        private KpiDayAggregationQuery $aggregationQuery,
        private KpiDailyRepository $kpiDailyRepository,
        private HospitalRepository $hospitalRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function aggregateForDate(\DateTimeImmutable $date): int
    {
        $calendarDate = $this->normalizeCalendarDate($date);

        return $this->entityManager->wrapInTransaction(function () use ($calendarDate): int {
            $this->kpiDailyRepository->deleteByDate($calendarDate);

            $count = 0;
            $perHospital = $this->aggregationQuery->fetchPerHospital($calendarDate);

            foreach ($perHospital as $row) {
                $hospital = $this->hospitalRepository->find($row['hospitalId']);
                if (!$hospital instanceof Hospital) {
                    continue;
                }

                $this->entityManager->persist($this->createEntity($calendarDate, $hospital, $this->metricsFromRow($row)));
                ++$count;
            }

            $global = $this->aggregationQuery->fetchGlobal($calendarDate);
            if (null !== $global) {
                $this->entityManager->persist($this->createEntity($calendarDate, null, $global));
                ++$count;
            }

            $this->entityManager->flush();

            return $count;
        });
    }

    /**
     * @param array{
     *     importsCount: int,
     *     successfulImportsCount: int,
     *     failedImportsCount: int,
     *     recordsTotal: int,
     *     recordsRejected: int,
     *     hospitalId?: int,
     * } $row
     *
     * @return array{
     *     importsCount: int,
     *     successfulImportsCount: int,
     *     failedImportsCount: int,
     *     recordsTotal: int,
     *     recordsRejected: int,
     * }
     */
    private function metricsFromRow(array $row): array
    {
        return [
            'importsCount' => $row['importsCount'],
            'successfulImportsCount' => $row['successfulImportsCount'],
            'failedImportsCount' => $row['failedImportsCount'],
            'recordsTotal' => $row['recordsTotal'],
            'recordsRejected' => $row['recordsRejected'],
        ];
    }

    /**
     * @param array{
     *     importsCount: int,
     *     successfulImportsCount: int,
     *     failedImportsCount: int,
     *     recordsTotal: int,
     *     recordsRejected: int,
     * } $metrics
     */
    private function createEntity(\DateTimeImmutable $date, ?Hospital $hospital, array $metrics): KpiDaily
    {
        $recordsTotal = $metrics['recordsTotal'];

        return new KpiDaily(
            date: $date,
            hospital: $hospital,
            importsCount: $metrics['importsCount'],
            successfulImportsCount: $metrics['successfulImportsCount'],
            recordsTotal: $recordsTotal,
            recordsProcessed: $recordsTotal,
            recordsRejected: $metrics['recordsRejected'],
            failedImportsCount: $metrics['failedImportsCount'],
        );
    }

    private function normalizeCalendarDate(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $tz = new \DateTimeZone('Europe/Berlin');

        return new \DateTimeImmutable($date->format('Y-m-d'), $tz);
    }
}
