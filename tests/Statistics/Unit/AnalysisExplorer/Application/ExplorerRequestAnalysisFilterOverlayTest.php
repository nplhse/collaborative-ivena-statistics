<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Application\ExplorerRequestAnalysisFilterOverlay;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisFilterOperator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ExplorerRequestAnalysisFilterOverlayTest extends TestCase
{
    private ExplorerRequestAnalysisFilterOverlay $overlay;

    protected function setUp(): void
    {
        $this->overlay = new ExplorerRequestAnalysisFilterOverlay();
    }

    public function testMapsResusAgeAndUrgencyDrawerFilters(): void
    {
        $request = Request::create('/statistics/analysis/explorer/allocations-over-time', 'GET', [
            'requiresResus' => '1',
            'age_group' => '18-64',
            'urgency' => '1',
        ]);

        $filters = $this->overlay->toStateFilters($request);

        self::assertSame([
            [
                'dimensionKey' => 'urgency',
                'operator' => AnalysisFilterOperator::Equals->value,
                'value' => 1,
            ],
            [
                'dimensionKey' => 'age_group',
                'operator' => AnalysisFilterOperator::Equals->value,
                'value' => '18-64',
            ],
            [
                'dimensionKey' => 'resus',
                'operator' => AnalysisFilterOperator::Equals->value,
                'value' => 1,
            ],
        ], $filters);
    }

    public function testIgnoresEmptyAndUnsupportedDrawerValues(): void
    {
        $request = Request::create('/', 'GET', [
            'requiresResus' => '',
            'isPregnant' => '1',
            'department' => 'abc',
        ]);

        self::assertSame([], $this->overlay->toStateFilters($request));
    }

    public function testMapsIndicationQueryParameter(): void
    {
        $request = Request::create('/', 'GET', ['indication' => '42']);

        self::assertSame([
            [
                'dimensionKey' => 'indication',
                'operator' => AnalysisFilterOperator::Equals->value,
                'value' => 42,
            ],
        ], $this->overlay->toStateFilters($request));
    }
}
