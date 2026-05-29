<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Repository;

use App\Statistics\Application\Contract\ProjectionEarliestDateProviderInterface;
use App\Statistics\Infrastructure\Entity\AllocationStatsProjection;
use App\Statistics\Infrastructure\Query\ProjectionDiagnosisQuery;
use App\Statistics\Infrastructure\Query\ProjectionFeatureQuery;
use App\Statistics\Infrastructure\Query\ProjectionTimeSeriesQuery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AllocationStatsProjection>
 */
final class AllocationStatsProjectionRepository extends ServiceEntityRepository implements ProjectionEarliestDateProviderInterface
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly ProjectionTimeSeriesQuery $timeSeriesQuery,
        private readonly ProjectionFeatureQuery $featureQuery,
        private readonly ProjectionDiagnosisQuery $diagnosisQuery,
    ) {
        parent::__construct($registry, AllocationStatsProjection::class);
    }

    /**
     * @param list<int>|null $hospitalIds
     */
    public function countCreatedInPeriod(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): int
    {
        return $this->timeSeriesQuery->countCreatedInPeriod($from, $toExclusive, $hospitalIds);
    }

    public function countBefore(\DateTimeImmutable $before): int
    {
        return $this->timeSeriesQuery->countBefore($before);
    }

    public function getEarliestCreatedAt(): ?\DateTimeImmutable
    {
        return $this->timeSeriesQuery->getEarliestCreatedAt();
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return list<array{year:int,month:int,count:int}>
     */
    public function countByMonthInPeriod(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->timeSeriesQuery->countByMonthInPeriod($from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string,int>
     */
    public function countByDayInPeriod(?\DateTimeImmutable $from, \DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->timeSeriesQuery->countByDayInPeriod($from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string,int>
     */
    public function countByCalendarMonthInPeriod(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->timeSeriesQuery->countByCalendarMonthInPeriod($from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string,int>
     */
    public function countGroupedByGenderInPeriod(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->timeSeriesQuery->countGroupedByGenderInPeriod($from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<int,int>
     */
    public function countGroupedByUrgencyInPeriod(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->timeSeriesQuery->countGroupedByUrgencyInPeriod($from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, array<string,int>>
     */
    public function bucketByMonthAndGender(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->timeSeriesQuery->bucketByMonthAndGender($from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, array<string,int>>
     */
    public function bucketByMonthAndUrgency(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->timeSeriesQuery->bucketByMonthAndUrgency($from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, array<string,int>>
     */
    public function bucketByDayAndGender(?\DateTimeImmutable $from, \DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->timeSeriesQuery->bucketByDayAndGender($from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, array<string,int>>
     */
    public function bucketByDayAndUrgency(?\DateTimeImmutable $from, \DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->timeSeriesQuery->bucketByDayAndUrgency($from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, array<string,int>>
     */
    public function bucketByCalendarMonthAndGender(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->timeSeriesQuery->bucketByCalendarMonthAndGender($from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, array<string,int>>
     */
    public function bucketByCalendarMonthAndUrgency(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->timeSeriesQuery->bucketByCalendarMonthAndUrgency($from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array{with_physician:int,cpr:int,ventilated:int,shock:int,pregnant:int,infectious:int}
     */
    public function clinicalFeatureCounts(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->featureQuery->clinicalFeatureCounts($from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array{cathlab:int,resus:int}
     */
    public function resourceFeatureCounts(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->featureQuery->resourceFeatureCounts($from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string,array{with_physician:int,cpr:int,ventilated:int,shock:int,pregnant:int,infectious:int,with_any:int}>
     */
    public function bucketClinicalFeaturesByMonth(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->featureQuery->bucketClinicalFeaturesByMonth($from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string,array{with_physician:int,cpr:int,ventilated:int,shock:int,pregnant:int,infectious:int,with_any:int}>
     */
    public function bucketClinicalFeaturesByDay(?\DateTimeImmutable $from, \DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->featureQuery->bucketClinicalFeaturesByDay($from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string,array{with_physician:int,cpr:int,ventilated:int,shock:int,pregnant:int,infectious:int,with_any:int}>
     */
    public function bucketClinicalFeaturesByCalendarMonth(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->featureQuery->bucketClinicalFeaturesByCalendarMonth($from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string,array{cathlab:int,resus:int,with_any:int}>
     */
    public function bucketResourcesByMonth(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->featureQuery->bucketResourcesByMonth($from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string,array{cathlab:int,resus:int,with_any:int}>
     */
    public function bucketResourcesByDay(?\DateTimeImmutable $from, \DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->featureQuery->bucketResourcesByDay($from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string,array{cathlab:int,resus:int,with_any:int}>
     */
    public function bucketResourcesByCalendarMonth(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->featureQuery->bucketResourcesByCalendarMonth($from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return list<array{label:string,count:int}>
     */
    public function fetchTopDiagnosisAggregates(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds, int $limit): array
    {
        return $this->diagnosisQuery->fetchTopDiagnosisAggregates($from, $toExclusive, $hospitalIds, $limit);
    }
}
