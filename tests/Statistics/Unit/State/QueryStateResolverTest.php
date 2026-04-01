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
                'date_range' => 'last_12_months',
            ],
            'view' => 'percent',
        ]);

        $state = $resolver->resolveFilters($request->query, $panel);

        self::assertSame('all_cases', $state->get('date_range'));
        self::assertSame('percent', $resolver->resolveViewMode($request->query, $panel, true));
    }
}
