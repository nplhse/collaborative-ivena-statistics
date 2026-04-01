<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\State;

use App\Statistics\Application\Filter\FilterRegistry;
use App\Statistics\Application\Panel\PanelFactory;
use App\Statistics\Application\State\QueryStateResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class QueryStateResolverTest extends TestCase
{
    public function testResolvesFiltersFromQuery(): void
    {
        $resolver = new QueryStateResolver(new FilterRegistry());
        $panel = new PanelFactory()->createDistributionPanel('urgency');

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
        self::assertSame('percent_of_total', $resolver->resolveViewMode($request->query, $panel, true));
    }

    public function testResolveViewModeFallsBackForInvalidMode(): void
    {
        $resolver = new QueryStateResolver(new FilterRegistry());
        $panel = new PanelFactory()->createDistributionPanel('urgency');
        $request = new Request(['view' => 'invalid']);

        self::assertSame('stacked', $resolver->resolveViewMode($request->query, $panel, true));
        self::assertSame('grouped', $resolver->resolveViewMode($request->query, $panel, false));
    }
}
