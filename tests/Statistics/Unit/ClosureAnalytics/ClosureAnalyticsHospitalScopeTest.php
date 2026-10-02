<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\ClosureAnalytics\Application\ClosureAnalyticsCriteriaFactory;
use App\Statistics\ClosureAnalytics\Application\ClosureAnalyticsHospitalScope;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsFilter;
use App\Statistics\ClosureAnalytics\UI\Http\Controller\ClosureAnalyticsScopeRedirector;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use PHPUnit\Framework\TestCase;

final class ClosureAnalyticsHospitalScopeTest extends TestCase
{
    public function testParticipantWithoutGrantsGetsNoHospitals(): void
    {
        $scope = $this->scope([], false);
        $user = $this->user([UserRole::USER, UserRole::PARTICIPANT]);

        self::assertSame([], $scope->allowedHospitalIds($user));
        self::assertSame([], $scope->effectiveHospitalIds($user, $this->filter(StatisticsFilterScope::Public), ClosureAnalyticsFilter::empty()));
    }

    public function testRoleWithoutParticipantDoesNotInheritHospitalGrants(): void
    {
        $scope = $this->scope([4], false);
        $user = $this->user([UserRole::USER, UserRole::CLOSURE_BETA]);

        self::assertSame([], $scope->allowedHospitalIds($user));
    }

    public function testMissingSelectionUsesOnlyAssignedHospitals(): void
    {
        $scope = $this->scope([4, 8], false);
        $user = $this->participant();

        self::assertSame(
            [4, 8],
            $scope->effectiveHospitalIds($user, $this->filter(StatisticsFilterScope::Public), ClosureAnalyticsFilter::empty()),
        );
        self::assertSame(
            [4],
            $scope->effectiveHospitalIds($user, $this->filter(StatisticsFilterScope::Hospital, 4), ClosureAnalyticsFilter::empty()),
        );
    }

    public function testSubmittedIdsNeverWidenTheAssignment(): void
    {
        $scope = $this->scope([4, 8], false);
        $user = $this->participant();
        $filter = $this->filter(StatisticsFilterScope::MyHospitals);

        self::assertSame([4], $scope->effectiveHospitalIds($user, $filter, $this->submitted([4, 99])));
        self::assertSame([], $scope->effectiveHospitalIds($user, $filter, $this->submitted([99])));
        self::assertSame([], $scope->effectiveHospitalIds($user, $filter, $this->submitted([])));
    }

    public function testHospitalScopeIntersectsTheDrawerInsteadOfReplacingIt(): void
    {
        $scope = $this->scope([4, 8], false);

        self::assertSame(
            [4],
            $scope->effectiveHospitalIds(
                $this->participant(),
                $this->filter(StatisticsFilterScope::Hospital, 4),
                $this->submitted([4, 8]),
            ),
        );
    }

    public function testAdminMayUseHospitalsOutsideOwnership(): void
    {
        $scope = $this->scope([1, 2, 3], true);
        $admin = $this->user([UserRole::USER, UserRole::ADMIN]);

        self::assertSame([1, 2, 3], $scope->allowedHospitalIds($admin));
        self::assertSame(
            [2],
            $scope->effectiveHospitalIds($admin, $this->filter(StatisticsFilterScope::Hospital, 2), ClosureAnalyticsFilter::empty()),
        );
    }

    public function testForeignEventKeysAreRejectedAndIntervalKeysStayInTheQuery(): void
    {
        $scope = $this->scope([4], false);
        $user = $this->participant();

        self::assertTrue($scope->eventKeyHospitalIsForbidden('group:9:action', $user));
        self::assertTrue($scope->eventKeyHospitalIsForbidden('cluster:9:abc', $user));
        self::assertFalse($scope->eventKeyHospitalIsForbidden('group:4:action', $user));
        self::assertFalse($scope->eventKeyHospitalIsForbidden('interval:15', $user));
    }

    public function testRedirectCanonicalizesBroadScopesWithoutExpandingAnEmptySelection(): void
    {
        $redirector = new ClosureAnalyticsScopeRedirector($this->scope([4, 8], false));
        $user = $this->participant();

        $public = $redirector->canonicalRedirectQuery(['scope' => 'public', 'period' => 'month', 'year' => '2026'], $user);
        self::assertSame('my_hospitals', $public['scope'] ?? null);
        self::assertSame('month', $public['period'] ?? null);
        self::assertArrayNotHasKey('closureHospitals', $public ?? []);

        $state = $redirector->canonicalRedirectQuery(['scope' => 'state:3', 'period' => 'all_time'], $user);
        self::assertSame('my_hospitals', $state['scope'] ?? null);
        self::assertArrayNotHasKey('state', $state ?? []);

        $dispatch = $redirector->canonicalRedirectQuery(['scope' => 'dispatch_area:9'], $user);
        self::assertSame('my_hospitals', $dispatch['scope'] ?? null);
        self::assertNotSame('public', $dispatch['scope'] ?? null);

        $foreign = $redirector->canonicalRedirectQuery(['scope' => 'hospital', 'hospital' => '99'], $user);
        self::assertSame('my_hospitals', $foreign['scope'] ?? null);

        $mixed = $redirector->canonicalRedirectQuery([
            'scope' => 'my_hospitals',
            'closureHospitals' => ['4', '99'],
            'closureHospitalsSubmitted' => '1',
        ], $user);
        self::assertSame('hospital', $mixed['scope'] ?? null);
        self::assertSame('4', $mixed['hospital'] ?? null);
        self::assertArrayNotHasKey('closureHospitals', $mixed ?? []);

        $onlyForeign = $redirector->canonicalRedirectQuery([
            'scope' => 'public',
            'closureHospitals' => ['99'],
        ], $user);
        self::assertSame('my_hospitals', $onlyForeign['scope'] ?? null);
        self::assertSame('1', $onlyForeign['closureHospitalsSubmitted'] ?? null);
        self::assertArrayNotHasKey('closureHospitals', $onlyForeign ?? []);
        self::assertArrayNotHasKey('hospital', $onlyForeign ?? []);

        self::assertNull($redirector->canonicalRedirectQuery($onlyForeign ?? [], $user));
        self::assertNull($redirector->canonicalRedirectQuery(['scope' => 'my_hospitals', 'period' => 'all_time'], $user));
        self::assertNull($redirector->canonicalRedirectQuery(['scope' => 'hospital', 'hospital' => '4'], $user));
    }

    public function testSingleAssignedHospitalIsSelectedAutomatically(): void
    {
        $redirector = new ClosureAnalyticsScopeRedirector($this->scope([4], false));
        $canonical = $redirector->canonicalRedirectQuery(['scope' => 'public', 'period' => 'all_time'], $this->participant());

        self::assertSame('hospital', $canonical['scope'] ?? null);
        self::assertSame('4', $canonical['hospital'] ?? null);
        self::assertSame('all_time', $canonical['period'] ?? null);
        self::assertNull($redirector->canonicalRedirectQuery($canonical ?? [], $this->participant()));
    }

    public function testUsersWithoutHospitalsAreNotRedirectedOntoABroaderScope(): void
    {
        $redirector = new ClosureAnalyticsScopeRedirector($this->scope([], false));

        self::assertNull($redirector->canonicalRedirectQuery(['scope' => 'public'], $this->participant()));
        self::assertNull($redirector->canonicalRedirectQuery(['scope' => 'dispatch_area:3'], $this->participant()));
        self::assertSame([], $this->scope([], false)->allowedHospitalIds(null));
        self::assertNull($redirector->canonicalRedirectQuery(['scope' => 'public'], null));
    }

    public function testForeignHospitalScopeDoesNotReplaceTheAssignment(): void
    {
        $scope = $this->scope([4, 8], false);

        self::assertSame(
            [4, 8],
            $scope->effectiveHospitalIds(
                $this->participant(),
                $this->filter(StatisticsFilterScope::Hospital, 99),
                ClosureAnalyticsFilter::empty(),
            ),
        );
        self::assertFalse($scope->eventKeyHospitalIsForbidden('not-an-event-key', $this->participant()));
    }

    public function testRedirectKeepsARealSubsetAndDropsCohortStateAndDispatchKeys(): void
    {
        $redirector = new ClosureAnalyticsScopeRedirector($this->scope([4, 8, 15], false));
        $user = $this->participant();

        $subset = $redirector->canonicalRedirectQuery([
            'scope' => 'public',
            'closureHospitals' => ['8', '4', '8'],
            'closureDepartments' => ['9'],
            'period' => 'year',
            'year' => '2026',
        ], $user);
        self::assertSame('my_hospitals', $subset['scope'] ?? null);
        self::assertSame(['4', '8'], $subset['closureHospitals'] ?? null);
        self::assertSame('1', $subset['closureHospitalsSubmitted'] ?? null);
        self::assertSame(['9'], $subset['closureDepartments'] ?? null);
        self::assertSame('year', $subset['period'] ?? null);
        self::assertNull($redirector->canonicalRedirectQuery($subset ?? [], $user));

        $cohort = $redirector->canonicalRedirectQuery(['scope' => 'hospital_cohort', 'cohort' => 'location:1'], $user);
        self::assertSame('my_hospitals', $cohort['scope'] ?? null);
        self::assertArrayNotHasKey('cohort', $cohort ?? []);

        $stateKey = $redirector->canonicalRedirectQuery(['scope' => 'my_hospitals', 'state' => '3'], $user);
        self::assertArrayNotHasKey('state', $stateKey ?? []);

        self::assertNull($redirector->canonicalRedirectQuery(['scope' => 'hospital:4'], $user));
        $foreignComposite = $redirector->canonicalRedirectQuery(['scope' => 'hospital:99', 'dispatch_area' => '2'], $user);
        self::assertSame('my_hospitals', $foreignComposite['scope'] ?? null);
        self::assertArrayNotHasKey('dispatch_area', $foreignComposite ?? []);
    }

    public function testCriteriaAlwaysCarryTheGrantedHospitalList(): void
    {
        $factory = new ClosureAnalyticsCriteriaFactory($this->scope([4, 8], false));
        $user = $this->participant();
        $public = $factory->create(
            $user,
            $this->filter(StatisticsFilterScope::Public),
            new ClosureAnalyticsFilter(closureUnits: ['Unit']),
        );

        self::assertSame([4, 8], $public->scope->hospitalIds);
        self::assertSame([], $public->closureUnits);
        self::assertSame([], $public->hospitalIds);

        $mine = $factory->create(
            $user,
            $this->filter(StatisticsFilterScope::MyHospitals),
            new ClosureAnalyticsFilter(closureUnits: ['Unit']),
        );
        self::assertSame([4, 8], $mine->scope->hospitalIds);
        self::assertSame(['Unit'], $mine->closureUnits);

        $empty = $factory->create(null, $this->filter(StatisticsFilterScope::Public));
        self::assertSame([], $empty->scope->hospitalIds);
    }

    /**
     * @param list<int> $hospitalIds
     */
    private function scope(array $hospitalIds, bool $admin): ClosureAnalyticsHospitalScope
    {
        $access = $this->createStub(HospitalAccessInterface::class);
        $access->method('isAdminHospitalScopeUser')->willReturn($admin);
        $access->method('accessibleHospitalIds')->willReturn($hospitalIds);

        return new ClosureAnalyticsHospitalScope($access);
    }

    /**
     * @param list<string> $roles
     */
    private function user(array $roles): User
    {
        $user = new User();
        $user->setRoles($roles);

        return $user;
    }

    private function participant(): User
    {
        return $this->user([UserRole::USER, UserRole::PARTICIPANT]);
    }

    private function filter(StatisticsFilterScope $scope, ?int $hospitalId = null): StatisticsFilter
    {
        return new StatisticsFilter($scope, $hospitalId, null, StatisticsFilterPeriod::AllTime);
    }

    /**
     * @param list<int> $hospitalIds
     */
    private function submitted(array $hospitalIds): ClosureAnalyticsFilter
    {
        return new ClosureAnalyticsFilter(hospitalIds: $hospitalIds, hospitalIdsSubmitted: true);
    }
}
