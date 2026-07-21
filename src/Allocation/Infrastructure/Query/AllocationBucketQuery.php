<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Query;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AllocationBucketQuery
{
    use HospitalScopeQueryTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function bucketByMonthAndGenderLast12Months(): array
    {
        $from = new \DateTimeImmutable('first day of this month')
            ->modify('-11 months')
            ->setTime(0, 0, 0);

        return $this->aggregateByMonthAndGender($from, null, null);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    public function bucketByMonthAndGenderLast12MonthsForHospitals(array $hospitalIds): array
    {
        if ([] === $hospitalIds) {
            return [];
        }

        $from = new \DateTimeImmutable('first day of this month')
            ->modify('-11 months')
            ->setTime(0, 0, 0);

        return $this->aggregateByMonthAndGender($from, null, $hospitalIds);
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function bucketByMonthAndGenderInRange(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
    ): array {
        return $this->aggregateByMonthAndGender($from, $toExclusive, null);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    public function bucketByMonthAndGenderInRangeForHospitals(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->aggregateByMonthAndGender($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function bucketByMonthAndUrgencyLast12Months(): array
    {
        $from = new \DateTimeImmutable('first day of this month')
            ->modify('-11 months')
            ->setTime(0, 0, 0);

        return $this->aggregateByMonthAndUrgency($from, null, null);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    public function bucketByMonthAndUrgencyLast12MonthsForHospitals(array $hospitalIds): array
    {
        if ([] === $hospitalIds) {
            return [];
        }

        $from = new \DateTimeImmutable('first day of this month')
            ->modify('-11 months')
            ->setTime(0, 0, 0);

        return $this->aggregateByMonthAndUrgency($from, null, $hospitalIds);
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function bucketByMonthAndUrgencyInRange(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
    ): array {
        return $this->aggregateByMonthAndUrgency($from, $toExclusive, null);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    public function bucketByMonthAndUrgencyInRangeForHospitals(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->aggregateByMonthAndUrgency($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array{cathlab: int, resus: int, with_any: int}>
     */
    public function bucketByMonthResourcesRequiredLast12Months(): array
    {
        return $this->aggregateByMonthResourcesRequired($this->last12MonthsFrom(), null, null);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array{cathlab: int, resus: int, with_any: int}>
     */
    public function bucketByMonthResourcesRequiredLast12MonthsForHospitals(array $hospitalIds): array
    {
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->aggregateByMonthResourcesRequired($this->last12MonthsFrom(), null, $hospitalIds);
    }

    /**
     * @return array<string, array{cathlab: int, resus: int, with_any: int}>
     */
    public function bucketByMonthResourcesRequiredInRange(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
    ): array {
        return $this->aggregateByMonthResourcesRequired($from, $toExclusive, null);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array{cathlab: int, resus: int, with_any: int}>
     */
    public function bucketByMonthResourcesRequiredInRangeForHospitals(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->aggregateByMonthResourcesRequired($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    public function bucketByMonthClinicalFeaturesLast12Months(): array
    {
        return $this->aggregateByMonthClinicalFeatures($this->last12MonthsFrom(), null, null);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    public function bucketByMonthClinicalFeaturesLast12MonthsForHospitals(array $hospitalIds): array
    {
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->aggregateByMonthClinicalFeatures($this->last12MonthsFrom(), null, $hospitalIds);
    }

    /**
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    public function bucketByMonthClinicalFeaturesInRange(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
    ): array {
        return $this->aggregateByMonthClinicalFeatures($from, $toExclusive, null);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    public function bucketByMonthClinicalFeaturesInRangeForHospitals(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->aggregateByMonthClinicalFeatures($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function bucketByCalendarMonthAndGenderInRange(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
    ): array {
        return $this->aggregateByCalendarMonthAndGender($from, $toExclusive, null);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    public function bucketByCalendarMonthAndGenderInRangeForHospitals(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->aggregateByCalendarMonthAndGender($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function bucketByDayAndGenderInRange(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
    ): array {
        return $this->aggregateByDayAndGender($from, $toExclusive, null);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    public function bucketByDayAndGenderInRangeForHospitals(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->aggregateByDayAndGender($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function bucketByCalendarMonthAndUrgencyInRange(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
    ): array {
        return $this->aggregateByCalendarMonthAndUrgency($from, $toExclusive, null);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    public function bucketByCalendarMonthAndUrgencyInRangeForHospitals(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->aggregateByCalendarMonthAndUrgency($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function bucketByDayAndUrgencyInRange(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
    ): array {
        return $this->aggregateByDayAndUrgency($from, $toExclusive, null);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    public function bucketByDayAndUrgencyInRangeForHospitals(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->aggregateByDayAndUrgency($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array{cathlab: int, resus: int, with_any: int}>
     */
    public function bucketByCalendarMonthResourcesRequiredInRange(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
    ): array {
        return $this->aggregateByCalendarMonthResourcesRequired($from, $toExclusive, null);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array{cathlab: int, resus: int, with_any: int}>
     */
    public function bucketByCalendarMonthResourcesRequiredInRangeForHospitals(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->aggregateByCalendarMonthResourcesRequired($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array{cathlab: int, resus: int, with_any: int}>
     */
    public function bucketByDayResourcesRequiredInRange(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
    ): array {
        return $this->aggregateByDayResourcesRequired($from, $toExclusive, null);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array{cathlab: int, resus: int, with_any: int}>
     */
    public function bucketByDayResourcesRequiredInRangeForHospitals(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->aggregateByDayResourcesRequired($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    public function bucketByCalendarMonthClinicalFeaturesInRange(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
    ): array {
        return $this->aggregateByCalendarMonthClinicalFeatures($from, $toExclusive, null);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    public function bucketByCalendarMonthClinicalFeaturesInRangeForHospitals(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->aggregateByCalendarMonthClinicalFeatures($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    public function bucketByDayClinicalFeaturesInRange(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
    ): array {
        return $this->aggregateByDayClinicalFeatures($from, $toExclusive, null);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    public function bucketByDayClinicalFeaturesInRangeForHospitals(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->aggregateByDayClinicalFeatures($from, $toExclusive, $hospitalIds);
    }

    private function last12MonthsFrom(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('first day of this month')
            ->modify('-11 months')
            ->setTime(0, 0, 0);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    private function aggregateByMonthAndGender(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        $rows = $this->fetchAllocationsInRange($from, $toExclusive, $hospitalIds);

        /** @var array<string, array<string, int>> $buckets */
        $buckets = [];
        foreach ($rows as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $ym = $createdAt->format('Y-m');
            $gender = $allocation->getGender() ?? AllocationGender::OTHER;
            $g = $gender->value;

            if (!isset($buckets[$ym][$g])) {
                $buckets[$ym][$g] = 0;
            }
            ++$buckets[$ym][$g];
        }

        return $buckets;
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    private function aggregateByMonthAndUrgency(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        $rows = $this->fetchAllocationsInRange($from, $toExclusive, $hospitalIds);

        /** @var array<string, array<string, int>> $buckets */
        $buckets = [];
        foreach ($rows as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $ym = $createdAt->format('Y-m');
            $urgency = $allocation->getUrgency() ?? AllocationUrgency::OUTPATIENT;
            $u = sprintf('%d', $urgency->value);

            if (!isset($buckets[$ym][$u])) {
                $buckets[$ym][$u] = 0;
            }
            ++$buckets[$ym][$u];
        }

        return $buckets;
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return list<Allocation>
     */
    private function fetchAllocationsInRange(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(Allocation::class, 'a')
            ->where('a.createdAt >= :from')
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->orderBy('a.createdAt', 'ASC');
        $this->applyCreatedAtBefore($qb, $toExclusive);
        $this->applyHospitalScope($qb, $hospitalIds);

        /** @var list<Allocation> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, array{cathlab: int, resus: int, with_any: int}>
     */
    private function aggregateByMonthResourcesRequired(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        /** @var array<string, array{cathlab: int, resus: int, with_any: int}> $buckets */
        $buckets = [];
        foreach ($this->fetchAllocationsInRange($from, $toExclusive, $hospitalIds) as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $ym = $createdAt->format('Y-m');
            if (!isset($buckets[$ym])) {
                $buckets[$ym] = ['cathlab' => 0, 'resus' => 0, 'with_any' => 0];
            }

            $this->incrementResourcesRequiredCells($allocation, $buckets[$ym]);
        }

        return $buckets;
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    private function aggregateByMonthClinicalFeatures(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        /** @var array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}> $buckets */
        $buckets = [];
        foreach ($this->fetchAllocationsInRange($from, $toExclusive, $hospitalIds) as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $ym = $createdAt->format('Y-m');
            if (!isset($buckets[$ym])) {
                $buckets[$ym] = $this->emptyClinicalFeatureCell();
            }

            $this->incrementClinicalFeatureCells($allocation, $buckets[$ym]);
        }

        return $buckets;
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    private function aggregateByCalendarMonthAndGender(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        /** @var array<string, array<string, int>> $buckets */
        $buckets = [];
        foreach ($this->fetchAllocationsInRange($from, $toExclusive, $hospitalIds) as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $calKey = sprintf('cal-%02d', (int) $createdAt->format('n'));
            $g = ($allocation->getGender() ?? AllocationGender::OTHER)->value;

            if (!isset($buckets[$calKey][$g])) {
                $buckets[$calKey][$g] = 0;
            }
            ++$buckets[$calKey][$g];
        }

        return $buckets;
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    private function aggregateByDayAndGender(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        /** @var array<string, array<string, int>> $buckets */
        $buckets = [];
        foreach ($this->fetchAllocationsInRange($from, $toExclusive, $hospitalIds) as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $dayKey = $createdAt->format('Y-m-d');
            $g = ($allocation->getGender() ?? AllocationGender::OTHER)->value;

            if (!isset($buckets[$dayKey][$g])) {
                $buckets[$dayKey][$g] = 0;
            }
            ++$buckets[$dayKey][$g];
        }

        return $buckets;
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    private function aggregateByCalendarMonthAndUrgency(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        /** @var array<string, array<string, int>> $buckets */
        $buckets = [];
        foreach ($this->fetchAllocationsInRange($from, $toExclusive, $hospitalIds) as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $calKey = sprintf('cal-%02d', (int) $createdAt->format('n'));
            $u = sprintf('%d', ($allocation->getUrgency() ?? AllocationUrgency::OUTPATIENT)->value);

            if (!isset($buckets[$calKey][$u])) {
                $buckets[$calKey][$u] = 0;
            }
            ++$buckets[$calKey][$u];
        }

        return $buckets;
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    private function aggregateByDayAndUrgency(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        /** @var array<string, array<string, int>> $buckets */
        $buckets = [];
        foreach ($this->fetchAllocationsInRange($from, $toExclusive, $hospitalIds) as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $dayKey = $createdAt->format('Y-m-d');
            $u = sprintf('%d', ($allocation->getUrgency() ?? AllocationUrgency::OUTPATIENT)->value);

            if (!isset($buckets[$dayKey][$u])) {
                $buckets[$dayKey][$u] = 0;
            }
            ++$buckets[$dayKey][$u];
        }

        return $buckets;
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, array{cathlab: int, resus: int, with_any: int}>
     */
    private function aggregateByCalendarMonthResourcesRequired(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        /** @var array<string, array{cathlab: int, resus: int, with_any: int}> $buckets */
        $buckets = [];
        foreach ($this->fetchAllocationsInRange($from, $toExclusive, $hospitalIds) as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $calKey = sprintf('cal-%02d', (int) $createdAt->format('n'));
            if (!isset($buckets[$calKey])) {
                $buckets[$calKey] = ['cathlab' => 0, 'resus' => 0, 'with_any' => 0];
            }

            $this->incrementResourcesRequiredCells($allocation, $buckets[$calKey]);
        }

        return $buckets;
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, array{cathlab: int, resus: int, with_any: int}>
     */
    private function aggregateByDayResourcesRequired(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        /** @var array<string, array{cathlab: int, resus: int, with_any: int}> $buckets */
        $buckets = [];
        foreach ($this->fetchAllocationsInRange($from, $toExclusive, $hospitalIds) as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $dayKey = $createdAt->format('Y-m-d');
            if (!isset($buckets[$dayKey])) {
                $buckets[$dayKey] = ['cathlab' => 0, 'resus' => 0, 'with_any' => 0];
            }

            $this->incrementResourcesRequiredCells($allocation, $buckets[$dayKey]);
        }

        return $buckets;
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    private function aggregateByCalendarMonthClinicalFeatures(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        return $this->accumulateClinicalBucketsForCalendarMonthKeys(
            $this->fetchAllocationsInRange($from, $toExclusive, $hospitalIds),
        );
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    private function aggregateByDayClinicalFeatures(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        return $this->accumulateClinicalBucketsForDayKeys(
            $this->fetchAllocationsInRange($from, $toExclusive, $hospitalIds),
        );
    }

    /**
     * @param list<Allocation> $rows
     *
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    private function accumulateClinicalBucketsForCalendarMonthKeys(array $rows): array
    {
        /** @var array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}> $buckets */
        $buckets = [];
        foreach ($rows as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $calKey = sprintf('cal-%02d', (int) $createdAt->format('n'));
            if (!isset($buckets[$calKey])) {
                $buckets[$calKey] = $this->emptyClinicalFeatureCell();
            }

            $this->incrementClinicalFeatureCells($allocation, $buckets[$calKey]);
        }

        return $buckets;
    }

    /**
     * @param list<Allocation> $rows
     *
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    private function accumulateClinicalBucketsForDayKeys(array $rows): array
    {
        /** @var array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}> $buckets */
        $buckets = [];
        foreach ($rows as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $dayKey = $createdAt->format('Y-m-d');
            if (!isset($buckets[$dayKey])) {
                $buckets[$dayKey] = $this->emptyClinicalFeatureCell();
            }

            $this->incrementClinicalFeatureCells($allocation, $buckets[$dayKey]);
        }

        return $buckets;
    }

    /**
     * @return array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}
     */
    private function emptyClinicalFeatureCell(): array
    {
        return [
            'with_physician' => 0,
            'cpr' => 0,
            'ventilated' => 0,
            'shock' => 0,
            'pregnant' => 0,
            'infectious' => 0,
            'with_any' => 0,
        ];
    }

    /**
     * @param array{cathlab: int, resus: int, with_any: int} $cell
     */
    private function incrementResourcesRequiredCells(Allocation $allocation, array &$cell): void
    {
        $requiresAny = false;
        if (true === $allocation->isRequiresCathlab()) {
            ++$cell['cathlab'];
            $requiresAny = true;
        }
        if (true === $allocation->isRequiresResus()) {
            ++$cell['resus'];
            $requiresAny = true;
        }
        if ($requiresAny) {
            ++$cell['with_any'];
        }
    }

    /**
     * @param array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int} $cell
     */
    private function incrementClinicalFeatureCells(Allocation $allocation, array &$cell): void
    {
        $hasAny = false;
        if (true === $allocation->isWithPhysician()) {
            ++$cell['with_physician'];
            $hasAny = true;
        }
        if (true === $allocation->isCPR()) {
            ++$cell['cpr'];
            $hasAny = true;
        }
        if (true === $allocation->isVentilated()) {
            ++$cell['ventilated'];
            $hasAny = true;
        }
        if (true === $allocation->isShock()) {
            ++$cell['shock'];
            $hasAny = true;
        }
        if (true === $allocation->isPregnant()) {
            ++$cell['pregnant'];
            $hasAny = true;
        }
        if ($allocation->getInfection() instanceof \App\Allocation\Domain\Entity\Infection) {
            ++$cell['infectious'];
            $hasAny = true;
        }
        if ($hasAny) {
            ++$cell['with_any'];
        }
    }
}
