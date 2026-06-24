<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\Application\Mapping\StatisticsAgeGroupFilter;
use App\Statistics\Infrastructure\Query\ProjectionDrawerFilterApplier;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ProjectionDrawerFilterApplierTest extends TestCase
{
    private ProjectionDrawerFilterApplier $applier;

    protected function setUp(): void
    {
        $this->applier = new ProjectionDrawerFilterApplier();
    }

    public function testAppliesAggregateAgeGroupFilter(): void
    {
        $qb = $this->createQueryBuilderMock();
        $qb->expects(self::once())
            ->method('andWhere')
            ->with(StatisticsAgeGroupFilter::sqlCondition('p.age', StatisticsAgeGroupFilter::UNDER_18));

        $this->applier->apply($qb, new StatisticsDrawerFilter(
            ageGroup: StatisticsAgeGroupFilter::UNDER_18,
        ));
    }

    public function testAppliesClinicalBooleanFilters(): void
    {
        $qb = $this->createQueryBuilderMock();
        $qb->expects(self::exactly(2))
            ->method('andWhere')
            ->willReturnCallback(function (string $condition) use ($qb): QueryBuilder {
                static $calls = 0;
                match ($calls++) {
                    0 => self::assertSame('p.requiresResus = :drawerRequiresResus', $condition),
                    1 => self::assertSame('p.infectionId IS NOT NULL', $condition),
                    default => self::fail('Unexpected andWhere call'),
                };

                return $qb;
            });
        $qb->expects(self::once())
            ->method('setParameter')
            ->with('drawerRequiresResus', true);

        $this->applier->apply($qb, new StatisticsDrawerFilter(
            requiresResus: true,
            isInfectious: true,
        ));
    }

    public function testAppliesDemographicAndAllocationFilters(): void
    {
        $qb = $this->createQueryBuilderMock();
        $qb->expects(self::exactly(5))
            ->method('andWhere')
            ->willReturnSelf();
        $qb->expects(self::exactly(4))
            ->method('setParameter')
            ->willReturnSelf();

        $this->applier->apply($qb, new StatisticsDrawerFilter(
            gender: 2,
            urgency: 1,
            ageGroup: StatisticsAgeGroupFilter::OVER_80,
            department: 10,
            speciality: 3,
        ));
    }

    public function testAppliesRemainingClinicalFlagsAndInfectionId(): void
    {
        $qb = $this->createQueryBuilderMock();
        $qb->expects(self::exactly(8))
            ->method('andWhere')
            ->willReturnCallback(function (string $condition) use ($qb): QueryBuilder {
                static $calls = 0;
                match ($calls++) {
                    0 => self::assertSame('p.requiresCathlab = :drawerRequiresCathlab', $condition),
                    1 => self::assertSame('p.isVentilated = :drawerIsVentilated', $condition),
                    2 => self::assertSame('p.isShock = :drawerIsShock', $condition),
                    3 => self::assertSame('p.isCpr = :drawerIsCpr', $condition),
                    4 => self::assertSame('p.isPregnant = :drawerIsPregnant', $condition),
                    5 => self::assertSame('p.isWorkAccident = :drawerIsWorkAccident', $condition),
                    6 => self::assertSame('p.infectionId IS NULL', $condition),
                    7 => self::assertSame('p.infectionId = :drawerInfectionId', $condition),
                    default => self::fail('Unexpected andWhere call'),
                };

                return $qb;
            });
        $qb->expects(self::exactly(7))
            ->method('setParameter')
            ->willReturnSelf();

        $this->applier->apply($qb, new StatisticsDrawerFilter(
            requiresCathlab: true,
            isVentilated: false,
            isShock: true,
            isCpr: false,
            isPregnant: true,
            isWorkAccident: false,
            isInfectious: false,
            infection: 42,
        ));
    }

    /**
     * @return QueryBuilder&MockObject
     */
    private function createQueryBuilderMock(): QueryBuilder
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();

        return $qb;
    }
}
