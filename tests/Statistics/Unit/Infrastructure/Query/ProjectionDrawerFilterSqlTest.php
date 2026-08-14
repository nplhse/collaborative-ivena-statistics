<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\Infrastructure\Query\ProjectionDrawerFilterSql;
use PHPUnit\Framework\TestCase;

final class ProjectionDrawerFilterSqlTest extends TestCase
{
    public function testInactiveFilterAddsNoConditions(): void
    {
        [$conditions, $params] = new ProjectionDrawerFilterSql()->apply(new StatisticsDrawerFilter());

        self::assertSame([], $conditions);
        self::assertSame([], $params);
    }

    public function testAppliesGenderAndUrgencyAsSqlFragments(): void
    {
        [$conditions, $params] = new ProjectionDrawerFilterSql()->apply(
            new StatisticsDrawerFilter(gender: 2, urgency: 1),
        );

        self::assertContains('gender_code = :drawer_gender', $conditions);
        self::assertContains('urgency_code = :drawer_urgency', $conditions);
        self::assertSame(2, $params['drawer_gender']);
        self::assertSame(1, $params['drawer_urgency']);
    }

    public function testAppliesRemainingFiltersWithColumnPrefix(): void
    {
        [$conditions, $params] = new ProjectionDrawerFilterSql()->apply(
            new StatisticsDrawerFilter(
                ageGroup: 'under_18',
                department: 4,
                speciality: 8,
                requiresResus: true,
                requiresCathlab: false,
                isVentilated: true,
                isShock: false,
                isCpr: true,
                isPregnant: false,
                isWorkAccident: true,
                infection: 12,
            ),
            'p',
        );

        self::assertContains('p.age IS NOT NULL AND p.age < 18', $conditions);
        self::assertContains('p.department_id = :drawer_department_id', $conditions);
        self::assertContains('p.speciality_id = :drawer_speciality_id', $conditions);
        self::assertContains('p.requires_resus = :drawer_requires_resus', $conditions);
        self::assertContains('p.requires_cathlab = :drawer_requires_cathlab', $conditions);
        self::assertContains('p.is_ventilated = :drawer_is_ventilated', $conditions);
        self::assertContains('p.is_shock = :drawer_is_shock', $conditions);
        self::assertContains('p.is_cpr = :drawer_is_cpr', $conditions);
        self::assertContains('p.is_pregnant = :drawer_is_pregnant', $conditions);
        self::assertContains('p.is_work_accident = :drawer_is_work_accident', $conditions);
        self::assertContains('p.infection_id = :drawer_infection_id', $conditions);
        self::assertSame(4, $params['drawer_department_id']);
        self::assertSame(8, $params['drawer_speciality_id']);
        self::assertTrue($params['drawer_requires_resus']);
        self::assertFalse($params['drawer_requires_cathlab']);
        self::assertSame(12, $params['drawer_infection_id']);
    }

    public function testInfectiousFlagUsesNullCheckAndUnknownAgeGroupIsIgnored(): void
    {
        [$infectious, $infectiousParams] = new ProjectionDrawerFilterSql()->apply(
            new StatisticsDrawerFilter(isInfectious: true),
        );
        [$notInfectious] = new ProjectionDrawerFilterSql()->apply(
            new StatisticsDrawerFilter(isInfectious: false),
        );
        [$unknownAge] = new ProjectionDrawerFilterSql()->apply(
            new StatisticsDrawerFilter(ageGroup: 'not-a-bucket'),
        );

        self::assertSame(['infection_id IS NOT NULL'], $infectious);
        self::assertSame([], $infectiousParams);
        self::assertSame(['infection_id IS NULL'], $notInfectious);
        self::assertSame([], $unknownAge);
    }
}
