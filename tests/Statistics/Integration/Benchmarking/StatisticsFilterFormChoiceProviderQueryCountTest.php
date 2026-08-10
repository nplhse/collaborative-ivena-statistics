<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Benchmarking;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Statistics\UI\Application\StatisticsFilterFormChoiceProvider;
use App\Statistics\UI\Application\StatisticsFilterSide;
use App\Tests\Statistics\Support\Benchmarking\EligibleBenchmarkScopeTrait;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Guards Issue #409: scope/hospital choice loading must stay batch + request-memoized
 * so LiveComponent form rebuilds do not N+1 State/DispatchArea rows.
 */
#[ResetDatabase]
final class StatisticsFilterFormChoiceProviderQueryCountTest extends KernelTestCase
{
    use EligibleBenchmarkScopeTrait;
    use Factories;

    /** First pass loads eligibility + batch name lookups + hospital summaries + cohorts. */
    private const int MAX_FIRST_PASS_QUERIES = 28;

    public function testRepeatedScopeChoiceLoadsStayWithinBudgetAndMemoize(): void
    {
        self::bootKernel();
        $provider = self::getContainer()->get(StatisticsFilterFormChoiceProvider::class);
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $this->seedEligibleBenchmarkScope($user, 'QueryCountA');

        for ($i = 0; $i < 4; ++$i) {
            $state = StateFactory::createOne(['name' => "QueryCountExtraState{$i}"]);
            $area = DispatchAreaFactory::createOne(['name' => "QueryCountExtraArea{$i}", 'state' => $state]);
            HospitalFactory::createOne([
                'name' => "QueryCountExtraHospA{$i}",
                'state' => $state,
                'dispatchArea' => $area,
                'owner' => $user,
            ]);
            HospitalFactory::createOne([
                'name' => "QueryCountExtraHospB{$i}",
                'state' => $state,
                'dispatchArea' => $area,
                'owner' => $user,
            ]);
        }

        $beforeFirst = $this->doctrineQueryCount();
        $this->loadScopeChoicesLikeFormRebuild($provider, $user);
        $firstPass = $this->doctrineQueryCount() - $beforeFirst;

        self::assertLessThanOrEqual(
            self::MAX_FIRST_PASS_QUERIES,
            $firstPass,
            sprintf('Expected at most %d DB queries on first scope-choice pass, got %d.', self::MAX_FIRST_PASS_QUERIES, $firstPass),
        );

        $beforeSecond = $this->doctrineQueryCount();
        $this->loadScopeChoicesLikeFormRebuild($provider, $user);
        $secondPass = $this->doctrineQueryCount() - $beforeSecond;

        self::assertSame(
            0,
            $secondPass,
            sprintf('Expected request-memoized second pass to issue 0 queries, got %d.', $secondPass),
        );
    }

    private function loadScopeChoicesLikeFormRebuild(
        StatisticsFilterFormChoiceProvider $provider,
        \App\User\Domain\Entity\User $user,
    ): void {
        $provider->scopePrimaryChoices($user, 'en');
        $provider->scopeDetailRequired('my_hospitals', $user, StatisticsFilterSide::Primary);
        $provider->scopeDetailChoices('my_hospitals', $user, StatisticsFilterSide::Primary, 'en');
        $provider->scopeDetailChoices('state', $user, StatisticsFilterSide::Primary, 'en');
        $provider->scopeDetailChoices('dispatch_area', $user, StatisticsFilterSide::Primary, 'en');
    }

    private function doctrineQueryCount(): int
    {
        self::assertTrue(self::getContainer()->has('doctrine.debug_data_holder'));
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $total = 0;
        foreach ($holder->getData() as $queries) {
            $total += \count($queries);
        }

        return $total;
    }
}
