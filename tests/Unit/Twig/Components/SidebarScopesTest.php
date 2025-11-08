<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Components;

use App\Model\Scope;
use App\Service\ScopeRoute;
use App\Service\Statistics\ScopeAvailabilityService;
use App\Service\Statistics\Util\DbScopeNameResolver;
use App\Service\Statistics\Util\Period;
use App\Twig\Components\SidebarScopes;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SidebarScopesTest extends TestCase
{
    /** @var ScopeRoute&MockObject */
    private ScopeRoute $route;

    /** @var ScopeAvailabilityService&MockObject */
    private ScopeAvailabilityService $availability;

    /** @var DbScopeNameResolver&MockObject */
    private DbScopeNameResolver $resolver;

    protected function setUp(): void
    {
        /** @var ScopeRoute&MockObject $route */
        $route = $this->createMock(ScopeRoute::class);
        $this->route = $route;

        /** @var ScopeAvailabilityService&MockObject $availability */
        $availability = $this->createMock(ScopeAvailabilityService::class);
        $this->availability = $availability;

        /** @var DbScopeNameResolver&MockObject $resolver */
        $resolver = $this->createMock(DbScopeNameResolver::class);
        $this->resolver = $resolver;
    }

    public function testMountBuildsGroupedSortedTreeAndSetsUrlsAndHasData(): void
    {
        // Arrange
        // availability returns a mix of types; counts/cnt werden akzeptiert
        $this->availability
            ->expects(self::once())
            ->method('getSidebarTree')
            ->willReturn([
                ['scope_type' => 'public', 'scope_id' => 'x', 'count' => 1],
                ['scope_type' => 'state', 'scope_id' => 'BY', 'cnt' => 2],
                ['scope_type' => 'dispatch_area', 'scope_id' => '42', 'cnt' => 0],
                ['scope_type' => 'hospital', 'scope_id' => '123', 'count' => 5],
                ['scope_type' => 'hospital_tier', 'scope_id' => 'basic', 'count' => 3],
                ['scope_type' => 'region', 'scope_id' => '99', 'count' => 1], // default group
            ]);

        // resolver returns names for those types that look up DB
        $this->resolver
            ->method('resolve')
            ->willReturnMap([
                ['hospital', '123', 'Saint Mary'],
                ['dispatch_area', '42', 'Area 42'],
                ['state', 'BY', 'Bavaria'],
            ]);

        // route will return a simple deterministic URL; we only check it is used
        $this->route
            ->method('toPath')
            ->willReturnCallback(function (string $t, string $i, string $g, string $k): string {
                return sprintf('/%s/%s/%s/%s', $t, $i, $g, $k);
            });

        $cmp = new SidebarScopes($this->route, $this->availability, $this->resolver);

        // Act
        $cmp->mount();

        // Assert
        // top-level groups are ordered by the configured order
        $groups = array_keys($cmp->tree);
        self::assertSame(
            ['Public', 'States', 'Dispatch Areas', 'Hospitals', 'Cohorts • Tier', 'Region'],
            $groups,
            'Groups must be ordered and default types get ucfirst(type) as group.'
        );

        // Check one item per group thoroughly

        // Public
        $public = $cmp->tree['Public'][0];
        self::assertSame('Public data', $public['label']);
        self::assertSame('/public/x/all/2010-01-01', $public['url']); // Period::ALL + ALL_ANCHOR_DATE
        self::assertTrue($public['hasData']);
        self::assertSame(1, $public['count']);
        self::assertInstanceOf(Scope::class, $public['scope']);
        self::assertSame('public', $public['scope']->scopeType);

        // States
        $state = $cmp->tree['States'][0];
        self::assertSame('State: Bavaria', $state['label']);
        self::assertSame('/state/BY/all/2010-01-01', $state['url']);
        self::assertTrue($state['hasData']);
        self::assertSame(2, $state['count']);

        // Dispatch Areas (Area 42 has 0 -> hasData=false)
        $da = $cmp->tree['Dispatch Areas'][0];
        self::assertSame('Dispatch Area: Area 42', $da['label']);
        self::assertSame('/dispatch_area/42/all/2010-01-01', $da['url']);
        self::assertFalse($da['hasData']);
        self::assertSame(0, $da['count']);

        // Hospitals
        $h = $cmp->tree['Hospitals'][0];
        self::assertSame('Hospital: Saint Mary', $h['label']);
        self::assertSame('/hospital/123/all/2010-01-01', $h['url']);
        self::assertTrue($h['hasData']);
        self::assertSame(5, $h['count']);

        // Cohorts • Tier
        $tier = $cmp->tree['Cohorts • Tier'][0];
        self::assertSame('Tier: Basic', $tier['label']);
        self::assertSame('/hospital_tier/basic/all/2010-01-01', $tier['url']);
        self::assertTrue($tier['hasData']);
        self::assertSame(3, $tier['count']);

        // Default group "Region"
        $reg = $cmp->tree['Region'][0];
        self::assertSame('Region 99', $reg['label']);
        self::assertSame('/region/99/all/2010-01-01', $reg['url']);
        self::assertTrue($reg['hasData']);
        self::assertSame(1, $reg['count']);
    }

    public function testUrlForAndIsActiveBehaveAsExpected(): void
    {
        // Arrange
        $this->route
            ->method('toPath')
            ->willReturn('/ok');

        $cmp = new SidebarScopes($this->route, $this->availability, $this->resolver);

        $s1 = new Scope('state', 'BY', Period::ALL, Period::ALL_ANCHOR_DATE);
        $s2 = new Scope('state', 'HE', Period::ALL, Period::ALL_ANCHOR_DATE);

        $cmp->current = $s1;

        // Act
        $url = $cmp->urlFor($s1);
        $isActive1 = $cmp->isActive($s1);
        $isActive2 = $cmp->isActive($s2);

        // Assert
        self::assertSame('/ok', $url);
        self::assertTrue($isActive1);
        self::assertFalse($isActive2);
    }
}
