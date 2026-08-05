<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Unit\RequestTracking;

use App\Analytics\Application\RequestTracking\FeatureAreaResolver;
use App\Analytics\Domain\Enum\FeatureArea;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FeatureAreaResolverTest extends TestCase
{
    private FeatureAreaResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new FeatureAreaResolver();
    }

    #[DataProvider('provideRoutes')]
    public function testResolve(?string $route, FeatureArea $expected): void
    {
        self::assertSame($expected, $this->resolver->resolve($route));
    }

    /**
     * @return iterable<string, array{0: ?string, 1: FeatureArea}>
     */
    public static function provideRoutes(): iterable
    {
        yield 'null' => [null, FeatureArea::Other];
        yield 'home' => ['app_default', FeatureArea::Home];
        yield 'dashboard' => ['app_stats_dashboard', FeatureArea::Dashboard];
        yield 'analysis' => ['app_stats_analysis_explorer', FeatureArea::Analysis];
        yield 'statistics' => ['app_stats_benchmarking', FeatureArea::Statistics];
        yield 'explore' => ['app_explore_allocation_list', FeatureArea::Explore];
        yield 'import' => ['app_import_new', FeatureArea::Import];
        yield 'export' => ['app_hospitals_export_allocations', FeatureArea::Export];
        yield 'admin' => ['app_admin_dashboard', FeatureArea::Admin];
        yield 'blog' => ['app_blog_show', FeatureArea::Blog];
        yield 'pages' => ['app_page_show', FeatureArea::Pages];
        yield 'other' => ['app_login', FeatureArea::Other];
    }
}
