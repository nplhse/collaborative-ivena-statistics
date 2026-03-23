<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Twig\Components;

use App\Allocation\Application\Contract\DispatchAreaLookupInterface;
use App\Allocation\Application\Contract\HospitalLookupInterface;
use App\Allocation\Application\Contract\StateLookupInterface;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\State;
use App\Statistics\Domain\Enum\TimeGridMode;
use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Availability\ScopeAvailabilityService;
use App\Statistics\Infrastructure\Util\Period;
use App\Statistics\UI\Twig\Components\ChooseScopePicker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

final class ChooseScopePickerTest extends TestCase
{
    private RequestStack $requestStack;

    protected function setUp(): void
    {
        $this->requestStack = new RequestStack();
    }

    private function makePicker(
        ?RouterInterface $router = null,
        ?ScopeAvailabilityService $availability = null,
        ?HospitalLookupInterface $hospitalRepository = null,
        ?DispatchAreaLookupInterface $dispatchAreaRepository = null,
        ?StateLookupInterface $stateRepository = null,
    ): ChooseScopePicker {
        return new ChooseScopePicker(
            $this->requestStack,
            $router ?? $this->createStub(RouterInterface::class),
            $availability ?? $this->createStub(ScopeAvailabilityService::class),
            $hospitalRepository ?? $this->createStub(HospitalLookupInterface::class),
            $dispatchAreaRepository ?? $this->createStub(DispatchAreaLookupInterface::class),
            $stateRepository ?? $this->createStub(StateLookupInterface::class),
        );
    }

    public function testPrimaryGroupsSortsKnownLabelsBeforeOthers(): void
    {
        $availability = $this->createStub(ScopeAvailabilityService::class);
        $availability->method('getSidebarTree')->willReturn([
            ['scope_type' => 'hospital', 'scope_id' => '2', 'count' => 1],
            ['scope_type' => 'public', 'scope_id' => 'all', 'count' => 10],
            ['scope_type' => 'state', 'scope_id' => '1', 'count' => 3],
        ]);

        $cmp = $this->makePicker(availability: $availability);
        $cmp->primary = new Scope('public', 'all', Period::YEAR, '2025-01-01');

        $groups = $cmp->primaryGroups();

        self::assertSame(['Public', 'State', 'Hospital'], array_column($groups, 'label'));
    }

    public function testModeUrlReturnsHashWhenNoCurrentRequest(): void
    {
        $cmp = $this->makePicker();
        self::assertSame('#', $cmp->modeUrl('compare'));
    }

    public function testModeUrlMergesRouteParamsQueryAndMode(): void
    {
        $request = new Request(
            ['foo' => 'bar'],
            [],
            ['_route' => 'app_stats_dashboard', '_route_params' => ['primaryType' => 'public']]
        );
        $this->requestStack->push($request);

        /** @var RouterInterface&MockObject $router */
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with(
                'app_stats_dashboard',
                self::callback(static fn (array $params): bool => 'compare' === ($params['mode'] ?? null)
                    && 'bar' === ($params['foo'] ?? null)
                    && 'public' === ($params['primaryType'] ?? null))
            )
            ->willReturn('/generated');

        $cmp = $this->makePicker(router: $router);
        self::assertSame('/generated', $cmp->modeUrl('compare'));
    }

    public function testPrimaryGroupItemUrlsUseRouterWithExpectedParams(): void
    {
        $availability = $this->createStub(ScopeAvailabilityService::class);
        $availability->method('getSidebarTree')->willReturn([
            ['scope_type' => 'public', 'scope_id' => 'all', 'count' => 1],
        ]);

        $request = new Request(
            ['x' => '1'],
            [],
            ['_route' => 'app_dashboard', '_route_params' => ['a' => 'b']]
        );
        $this->requestStack->push($request);

        /** @var RouterInterface&MockObject $router */
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with(
                'app_dashboard',
                self::callback(fn (array $params): bool => 'default' === $params['preset']
                    && Period::YEAR === $params['gran']
                    && '2025-01-01' === $params['key']
                    && TimeGridMode::RAW->value === $params['mode']
                    && 'public' === $params['primaryType']
                    && 'all' === $params['primaryId']
                    && '1' === $params['x']
                    && 'b' === $params['a'])
            )
            ->willReturn('/pick-public');

        $cmp = $this->makePicker(router: $router, availability: $availability);
        $cmp->primary = new Scope('state', '5', Period::YEAR, '2025-01-01');
        $cmp->preset = 'default';
        $cmp->mode = TimeGridMode::RAW;

        $groups = $cmp->primaryGroups();
        self::assertSame('/pick-public', $groups[0]['items'][0]['url']);
    }

    public function testCompareModeKeepsBaseParamsWhenBuildingPrimaryUrl(): void
    {
        $availability = $this->createStub(ScopeAvailabilityService::class);
        $availability->method('getSidebarTree')->willReturn([
            ['scope_type' => 'public', 'scope_id' => 'all', 'count' => 1],
        ]);

        $request = new Request([], [], ['_route' => 'r', '_route_params' => []]);
        $this->requestStack->push($request);

        /** @var RouterInterface&MockObject $router */
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with(
                'r',
                self::callback(fn (array $params): bool => TimeGridMode::COMPARE->value === $params['mode']
                    && 'hospital' === $params['baseType']
                    && '9' === $params['baseId'])
            )
            ->willReturn('/with-base');

        $cmp = $this->makePicker(router: $router, availability: $availability);
        $cmp->primary = new Scope('state', '5', Period::MONTH, '2025-06-01');
        $cmp->base = new Scope('hospital', '9', Period::MONTH, '2025-06-01');
        $cmp->mode = TimeGridMode::COMPARE;

        $groups = $cmp->primaryGroups();
        self::assertSame('/with-base', $groups[0]['items'][0]['url']);
    }

    public function testNonCompareModeRemovesBaseParamsFromPrimaryUrl(): void
    {
        $availability = $this->createStub(ScopeAvailabilityService::class);
        $availability->method('getSidebarTree')->willReturn([
            ['scope_type' => 'public', 'scope_id' => 'all', 'count' => 1],
        ]);

        $request = new Request([], [], ['_route' => 'r', '_route_params' => ['baseType' => 'hospital', 'baseId' => '9']]);
        $this->requestStack->push($request);

        /** @var RouterInterface&MockObject $router */
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with(
                'r',
                self::callback(static fn (array $params): bool => !\array_key_exists('baseType', $params) && !\array_key_exists('baseId', $params))
            )
            ->willReturn('/no-base');

        $cmp = $this->makePicker(router: $router, availability: $availability);
        $cmp->primary = new Scope('public', 'all', Period::YEAR, '2025-01-01');
        $cmp->mode = TimeGridMode::RAW;

        $groups = $cmp->primaryGroups();
        self::assertSame('/no-base', $groups[0]['items'][0]['url']);
    }

    public function testRenderScopeLabelsAndRepositoryCaching(): void
    {
        $availability = $this->createStub(ScopeAvailabilityService::class);
        $availability->method('getSidebarTree')->willReturn([
            ['scope_type' => 'public', 'scope_id' => 'all', 'count' => 1],
            ['scope_type' => 'state', 'scope_id' => '7', 'count' => 1],
            ['scope_type' => 'hospital_cohort', 'scope_id' => 'bad', 'count' => 1],
        ]);

        $stateEntity = $this->createStub(State::class);
        $stateEntity->method('getName')->willReturn('Bayern');

        $stateRepository = $this->createStub(StateLookupInterface::class);
        $stateRepository->method('findById')->willReturnMap([[7, $stateEntity]]);

        $cmp = $this->makePicker(availability: $availability, stateRepository: $stateRepository);
        $cmp->primary = new Scope('public', 'all', Period::YEAR, '2025-01-01');

        $groups = $cmp->primaryGroups();
        $byLabel = [];
        foreach ($groups as $g) {
            $byLabel[$g['label']] = $g['items'];
        }

        self::assertSame('All scopes', $byLabel['Public'][0]['label']);
        self::assertSame('Bayern', $byLabel['State'][0]['label']);
        self::assertSame('Hospital_cohort bad', $byLabel['Hospital Cohort'][0]['label']);

        $groupsAgain = $cmp->primaryGroups();
        foreach ($groupsAgain as $g) {
            if ('State' === $g['label']) {
                self::assertSame('Bayern', $g['items'][0]['label']);
            }
        }
    }

    public function testHospitalCohortLabelFormatsTierAndLocation(): void
    {
        $availability = $this->createStub(ScopeAvailabilityService::class);
        $availability->method('getSidebarTree')->willReturn([
            ['scope_type' => 'hospital_cohort', 'scope_id' => 'maximal_rural', 'count' => 1],
        ]);

        $cmp = $this->makePicker(availability: $availability);
        $cmp->primary = new Scope('public', 'all', Period::YEAR, '2025-01-01');

        $groups = $cmp->primaryGroups();
        self::assertSame('Tier: Maximal — Location: Rural', $groups[0]['items'][0]['label']);
    }

    public function testBaseGroupsMarksSelectedBaseScope(): void
    {
        $availability = $this->createStub(ScopeAvailabilityService::class);
        $availability->method('getSidebarTree')->willReturn([
            ['scope_type' => 'public', 'scope_id' => 'all', 'count' => 1],
            ['scope_type' => 'hospital', 'scope_id' => '3', 'count' => 1],
        ]);

        $hospital = $this->createStub(Hospital::class);
        $hospital->method('getName')->willReturn('Klinikum');

        $hospitalRepository = $this->createStub(HospitalLookupInterface::class);
        $hospitalRepository->method('findById')->willReturnMap([[3, $hospital]]);

        $cmp = $this->makePicker(availability: $availability, hospitalRepository: $hospitalRepository);
        $cmp->primary = new Scope('public', 'all', Period::YEAR, '2025-01-01');
        $cmp->base = new Scope('hospital', '3', Period::YEAR, '2025-01-01');

        $groups = $cmp->baseGroups();
        $hospitalGroup = array_find($groups, fn ($g): bool => 'Hospital' === $g['label']);
        self::assertNotNull($hospitalGroup);
        self::assertTrue($hospitalGroup['items'][0]['selected']);
        self::assertSame('Klinikum', $hospitalGroup['items'][0]['label']);
    }

    public function testIsCompareReflectsMode(): void
    {
        $cmp = $this->makePicker();
        $cmp->mode = TimeGridMode::COMPARE;
        self::assertTrue($cmp->isCompare());
        $cmp->mode = TimeGridMode::RAW;
        self::assertFalse($cmp->isCompare());
    }
}
