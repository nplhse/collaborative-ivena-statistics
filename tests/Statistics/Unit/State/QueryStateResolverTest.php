<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\State;

use App\Statistics\Application\Filter\FilterRegistry;
use App\Statistics\Application\Panel\Distribution\DimensionKind;
use App\Statistics\Application\Panel\PanelDefinition;
use App\Statistics\Application\State\QueryStateResolver;
use App\Tests\Statistics\Fixtures\DistributionPanelFixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class QueryStateResolverTest extends TestCase
{
    public function testResolvesFiltersFromQuery(): void
    {
        $resolver = new QueryStateResolver(new FilterRegistry());
        $panel = DistributionPanelFixtures::urgency();

        $request = new Request([
            'f' => [
                'date_range' => [
                    'from' => '2025-01-01',
                    'to' => '2025-01-31',
                ],
            ],
            'view' => 'percent_of_total',
        ]);

        $state = $resolver->resolveFilters($request->query, $panel);

        self::assertSame(
            ['from' => '2025-01-01', 'to' => '2025-01-31'],
            $state->get('date_range')
        );
        self::assertSame([], $state->get('hospital_tier'));
        self::assertSame([], $state->get('hospital_location'));
        self::assertSame('percent_of_total', $resolver->resolveViewMode($request->query, $panel, true));
    }

    public function testResolveViewModeFallsBackForInvalidMode(): void
    {
        $resolver = new QueryStateResolver(new FilterRegistry());
        $panel = DistributionPanelFixtures::urgency();
        $request = new Request(['view' => 'invalid']);

        self::assertSame('stacked', $resolver->resolveViewMode($request->query, $panel, true));
        self::assertSame('grouped', $resolver->resolveViewMode($request->query, $panel, false));
    }

    public function testResolvesLastTwelveMonthsStringFromQuery(): void
    {
        $resolver = new QueryStateResolver(new FilterRegistry());
        $panel = DistributionPanelFixtures::urgency();
        $request = new Request(['f' => ['date_range' => 'last_12_months']]);

        $state = $resolver->resolveFilters($request->query, $panel);

        self::assertSame('last_12_months', $state->get('date_range'));
    }

    public function testPanelFilterDefaultsOverrideRegistryDefaults(): void
    {
        $resolver = new QueryStateResolver(new FilterRegistry());
        $panel = new PanelDefinition(
            key: 'custom',
            type: 'distribution',
            dimensionKind: DimensionKind::Column,
            dimensionField: 'urgency_code',
            dimensionLabel: 'x',
            groupByField: null,
            groupByLabel: null,
            filters: ['date_range', 'hospital_tier', 'hospital_location'],
            options: [],
            controls: [],
            filterDefaults: [
                'date_range' => 'last_12_months',
                'hospital_tier' => [1],
                'hospital_location' => [2],
            ],
        );
        $request = new Request([]);

        $state = $resolver->resolveFilters($request->query, $panel);

        self::assertSame('last_12_months', $state->get('date_range'));
        self::assertSame([1], $state->get('hospital_tier'));
        self::assertSame([2], $state->get('hospital_location'));
    }
}
