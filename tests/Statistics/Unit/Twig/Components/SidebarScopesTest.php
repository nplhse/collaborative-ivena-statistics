<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Twig\Components;

use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Availability\ScopeAvailabilityService;
use App\Statistics\Infrastructure\Resolver\DbScopeNameResolver;
use App\Statistics\Infrastructure\Util\Period;
use App\Statistics\Infrastructure\Util\ScopeRoute;
use App\Statistics\UI\Twig\Components\SidebarScopes;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SidebarScopesTest extends TestCase
{
    public function testMountBuildsGroupedSortedTreeAndSetsUrlsAndHasData(): void
    {
        /** @var ScopeAvailabilityService&MockObject $availability */
        $availability = $this->createMock(ScopeAvailabilityService::class);
        $availability
            ->expects(self::once())
            ->method('getSidebarTree')
            ->willReturn([
                ['scope_type' => 'public', 'scope_id' => 'x', 'count' => 1],
                ['scope_type' => 'state', 'scope_id' => 'BY', 'cnt' => 2],
                ['scope_type' => 'dispatch_area', 'scope_id' => '42', 'cnt' => 0],
                ['scope_type' => 'hospital', 'scope_id' => '123', 'count' => 5],
                ['scope_type' => 'hospital_tier', 'scope_id' => 'basic', 'count' => 3],
                ['scope_type' => 'region', 'scope_id' => '99', 'count' => 1],
            ]);

        $resolver = $this->createStub(DbScopeNameResolver::class);
        $resolver
            ->method('resolve')
            ->willReturnMap([
                ['hospital', '123', 'Saint Mary'],
                ['dispatch_area', '42', 'Area 42'],
                ['state', 'BY', 'Bavaria'],
            ]);

        $route = $this->createStub(ScopeRoute::class);
        $route
            ->method('toPath')
            ->willReturnCallback(fn (string $t, string $i, string $g, string $k): string => sprintf('/%s/%s/%s/%s', $t, $i, $g, $k));

        $cmp = new SidebarScopes($route, $availability, $resolver);

        $cmp->mount();

        $groups = array_keys($cmp->tree);
        self::assertSame(
            ['Public', 'States', 'Dispatch Areas', 'Hospitals', 'Cohorts • Tier', 'Region'],
            $groups,
            'Groups must be ordered and default types get ucfirst(type) as group.'
        );

        $public = $cmp->tree['Public'][0];
        self::assertSame('Public data', $public['label']);
        self::assertSame('/public/x/all/2010-01-01', $public['url']);
        self::assertTrue($public['hasData']);
        self::assertSame(1, $public['count']);
        self::assertInstanceOf(Scope::class, $public['scope']);
        self::assertSame('public', $public['scope']->scopeType);

        $state = $cmp->tree['States'][0];
        self::assertSame('State: Bavaria', $state['label']);
        self::assertSame('/state/BY/all/2010-01-01', $state['url']);
        self::assertTrue($state['hasData']);
        self::assertSame(2, $state['count']);

        $da = $cmp->tree['Dispatch Areas'][0];
        self::assertSame('Dispatch Area: Area 42', $da['label']);
        self::assertSame('/dispatch_area/42/all/2010-01-01', $da['url']);
        self::assertFalse($da['hasData']);
        self::assertSame(0, $da['count']);

        $h = $cmp->tree['Hospitals'][0];
        self::assertSame('Hospital: Saint Mary', $h['label']);
        self::assertSame('/hospital/123/all/2010-01-01', $h['url']);
        self::assertTrue($h['hasData']);
        self::assertSame(5, $h['count']);

        $tier = $cmp->tree['Cohorts • Tier'][0];
        self::assertSame('Tier: Basic', $tier['label']);
        self::assertSame('/hospital_tier/basic/all/2010-01-01', $tier['url']);
        self::assertTrue($tier['hasData']);
        self::assertSame(3, $tier['count']);

        $reg = $cmp->tree['Region'][0];
        self::assertSame('Region 99', $reg['label']);
        self::assertSame('/region/99/all/2010-01-01', $reg['url']);
        self::assertTrue($reg['hasData']);
        self::assertSame(1, $reg['count']);
    }

    public function testUrlForAndIsActiveBehaveAsExpected(): void
    {
        $route = $this->createStub(ScopeRoute::class);
        $route->method('toPath')->willReturn('/ok');

        $cmp = new SidebarScopes(
            $route,
            $this->createStub(ScopeAvailabilityService::class),
            $this->createStub(DbScopeNameResolver::class),
        );

        $s1 = new Scope('state', 'BY', Period::ALL, Period::ALL_ANCHOR_DATE);
        $s2 = new Scope('state', 'HE', Period::ALL, Period::ALL_ANCHOR_DATE);

        $cmp->current = $s1;

        $url = $cmp->urlFor($s1);
        $isActive1 = $cmp->isActive($s1);
        $isActive2 = $cmp->isActive($s2);

        self::assertSame('/ok', $url);
        self::assertTrue($isActive1);
        self::assertFalse($isActive2);
    }
}
