<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application;

use App\Statistics\Application\ComparisonScopeResolver;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ComparisonScopeResolverTest extends KernelTestCase
{
    public function testParsesComparisonScopeColonSyntax(): void
    {
        self::bootKernel();
        $resolver = self::getContainer()->get(ComparisonScopeResolver::class);

        $primaryFilter = new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::All,
        );
        $comparisonFilter = $resolver->resolve(
            new Request(query: ['comparison_scope' => 'hospital_cohort:urban_basic']),
            null,
            $primaryFilter,
        );

        self::assertSame('all', $comparisonFilter->period->value);
        self::assertContains($comparisonFilter->scope->value, ['hospital_cohort', 'public']);
    }

    public function testDefaultsComparisonPeriodToPrimaryPeriod(): void
    {
        self::bootKernel();
        $resolver = self::getContainer()->get(ComparisonScopeResolver::class);

        $primaryFilter = new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::Year,
            2025,
        );
        $comparisonFilter = $resolver->resolve(
            new Request(query: ['comparison_scope' => 'hospital_cohort:urban_basic']),
            null,
            $primaryFilter,
        );

        self::assertSame(StatisticsFilterPeriod::Year, $comparisonFilter->period);
        self::assertSame(2025, $comparisonFilter->referenceYear);
    }

    public function testUsesExplicitComparisonPeriodAndAnchors(): void
    {
        self::bootKernel();
        $resolver = self::getContainer()->get(ComparisonScopeResolver::class);

        $primaryFilter = new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::Year,
            2025,
        );
        $comparisonFilter = $resolver->resolve(
            new Request(query: [
                'comparison_scope' => 'hospital_cohort:urban_basic',
                'comparison_period' => 'month',
                'comparison_year' => '2024',
                'comparison_month' => '2',
            ]),
            null,
            $primaryFilter,
        );

        self::assertSame(StatisticsFilterPeriod::Month, $comparisonFilter->period);
        self::assertSame(2024, $comparisonFilter->referenceYear);
        self::assertSame(2, $comparisonFilter->referenceMonth);
    }

    public function testParsesPublicComparisonScope(): void
    {
        self::bootKernel();
        $resolver = self::getContainer()->get(ComparisonScopeResolver::class);

        $comparisonFilter = $resolver->resolve(
            new Request(query: ['comparison_scope' => 'public']),
            null,
            new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::All),
        );

        self::assertSame(StatisticsFilterScope::Public, $comparisonFilter->scope);
    }

    public function testParsesStateComparisonScopeColonSyntax(): void
    {
        self::bootKernel();
        $resolver = self::getContainer()->get(ComparisonScopeResolver::class);

        $comparisonFilter = $resolver->resolve(
            new Request(query: ['comparison_scope' => 'state:999999']),
            null,
            new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::All),
        );

        self::assertSame(StatisticsFilterScope::Public, $comparisonFilter->scope);
        self::assertNull($comparisonFilter->stateId);
    }

    public function testPrimaryMyHospitalsWithoutAccessStillResolvesComparisonFilter(): void
    {
        self::bootKernel();
        $resolver = self::getContainer()->get(ComparisonScopeResolver::class);
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);

        $comparisonFilter = $resolver->resolve(
            new Request(query: ['comparison_scope' => 'public']),
            $user,
            new StatisticsFilter(StatisticsFilterScope::MyHospitals, null, null, StatisticsFilterPeriod::All),
        );

        self::assertSame(StatisticsFilterScope::Public, $comparisonFilter->scope);
    }
}
