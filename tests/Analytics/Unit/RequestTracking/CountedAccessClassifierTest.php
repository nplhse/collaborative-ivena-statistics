<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Unit\RequestTracking;

use App\Analytics\Application\RequestTracking\CountedAccessClassifier;
use App\Analytics\Domain\Enum\DeviceType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CountedAccessClassifierTest extends TestCase
{
    private CountedAccessClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new CountedAccessClassifier();
    }

    #[DataProvider('provideRequests')]
    public function testIsCountedAccess(
        string $method,
        int $status,
        ?string $route,
        DeviceType $device,
        bool $isFrame,
        bool $isBackground,
        bool $expected,
    ): void {
        self::assertSame(
            $expected,
            $this->classifier->isCountedAccess($method, $status, $route, $device, $isFrame, $isBackground),
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: int, 2: ?string, 3: DeviceType, 4: bool, 5: bool, 6: bool}>
     */
    public static function provideRequests(): iterable
    {
        yield 'page view' => ['GET', 200, 'app_stats_dashboard', DeviceType::Desktop, false, false, true];
        yield 'page view, lowercase method' => ['get', 200, 'app_explore_hospital_show', DeviceType::Mobile, false, false, true];
        yield 'HEAD request' => ['HEAD', 200, 'app_page_show', DeviceType::Tablet, false, false, true];
        yield 'other 2xx status' => ['GET', 204, 'app_stats_dashboard', DeviceType::Desktop, false, false, true];
        yield 'POST' => ['POST', 200, 'app_login', DeviceType::Desktop, false, false, false];
        yield 'redirect' => ['GET', 302, 'app_stats_dashboard', DeviceType::Desktop, false, false, false];
        yield 'not modified' => ['GET', 304, 'app_stats_dashboard', DeviceType::Desktop, false, false, false];
        yield 'not found' => ['GET', 404, 'app_page_show', DeviceType::Desktop, false, false, false];
        yield 'server error' => ['GET', 500, 'app_stats_dashboard', DeviceType::Desktop, false, false, false];
        yield 'bot' => ['GET', 200, 'app_default', DeviceType::Bot, false, false, false];
        yield 'turbo frame request on a page route' => ['GET', 200, 'app_stats_closure_analytics', DeviceType::Desktop, true, false, false];
        yield 'xhr or prefetch' => ['GET', 200, 'app_stats_dashboard', DeviceType::Desktop, false, true, false];
        yield 'no route' => ['GET', 200, null, DeviceType::Desktop, false, false, false];
        yield 'live component' => ['GET', 200, 'ux_live_component', DeviceType::Desktop, false, false, false];
        yield 'robots.txt' => ['GET', 200, 'app_robots_txt', DeviceType::Desktop, false, false, false];
        yield 'xml sitemap' => ['GET', 200, 'app_xml_sitemap', DeviceType::Desktop, false, false, false];
        yield 'html sitemap is a page' => ['GET', 200, 'app_sitemap', DeviceType::Desktop, false, false, true];
        yield 'json polling' => ['GET', 200, 'app_import_status', DeviceType::Desktop, false, false, false];
        yield 'lazy frame route' => ['GET', 200, 'app_stats_overview_self_benchmark', DeviceType::Desktop, false, false, false];
        yield 'frame suffix' => ['GET', 200, 'app_stats_closure_analytics_timeline_frame', DeviceType::Desktop, false, false, false];
        yield 'drawer suffix' => ['GET', 200, 'app_stats_data_quality_drawer', DeviceType::Desktop, false, false, false];
        yield 'csv export suffix' => ['GET', 200, 'app_stats_top_lists_export_csv', DeviceType::Desktop, false, false, false];
        yield 'cookie route prefix' => ['GET', 200, 'app_cookie_preferences', DeviceType::Desktop, false, false, false];
    }

    public function testStoredRequestIgnoresMissingHeaderInformation(): void
    {
        self::assertTrue($this->classifier->isCountedStoredRequest(200, 'app_stats_dashboard', DeviceType::Desktop));
        self::assertFalse($this->classifier->isCountedStoredRequest(200, 'app_stats_dashboard', DeviceType::Bot));
        self::assertFalse($this->classifier->isCountedStoredRequest(302, 'app_stats_dashboard', DeviceType::Desktop));
        self::assertFalse($this->classifier->isCountedStoredRequest(200, 'app_stats_overview_top_reports', DeviceType::Desktop));
        self::assertFalse($this->classifier->isCountedStoredRequest(200, null, DeviceType::Desktop));
    }
}
