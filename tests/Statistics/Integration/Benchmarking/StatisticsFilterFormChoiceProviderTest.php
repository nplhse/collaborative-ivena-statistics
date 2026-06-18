<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Benchmarking;

use App\Statistics\UI\Application\StatisticsFilterFormChoiceProvider;
use App\Statistics\UI\Application\StatisticsFilterSide;
use App\Tests\Statistics\Support\Benchmarking\EligibleBenchmarkScopeTrait;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class StatisticsFilterFormChoiceProviderTest extends KernelTestCase
{
    use EligibleBenchmarkScopeTrait;
    use Factories;

    private StatisticsFilterFormChoiceProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->provider = self::getContainer()->get(StatisticsFilterFormChoiceProvider::class);
    }

    public function testExposesEligibleScopeAndPeriodChoicesAfterMaterializedViewRefresh(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $scope = $this->seedEligibleBenchmarkScope($user->_real(), 'ChoiceProvider');

        $primaryChoices = $this->provider->scopePrimaryChoices($user->_real(), 'en');
        self::assertArrayHasKey('state', $primaryChoices);
        self::assertArrayHasKey('dispatch_area', $primaryChoices);
        self::assertArrayHasKey('hospital_cohort', $primaryChoices);
        self::assertArrayHasKey('my_hospitals', $primaryChoices);

        $stateDetails = $this->provider->scopeDetailChoices(
            'state',
            $user->_real(),
            StatisticsFilterSide::Primary,
            'en',
        );
        self::assertArrayHasKey((string) $scope['state']->getId(), $stateDetails);

        $dispatchDetails = $this->provider->scopeDetailChoices(
            'dispatch_area',
            $user->_real(),
            StatisticsFilterSide::Comparison,
            'en',
        );
        self::assertArrayHasKey((string) $scope['dispatchArea']->getId(), $dispatchDetails);

        $cohortDetails = $this->provider->scopeDetailChoices(
            'hospital_cohort',
            $user->_real(),
            StatisticsFilterSide::Primary,
            'en',
        );
        self::assertNotEmpty($cohortDetails);

        $hospitalDetails = $this->provider->scopeDetailChoices(
            'my_hospitals',
            $user->_real(),
            StatisticsFilterSide::Primary,
            'en',
        );
        self::assertArrayHasKey((string) $scope['hospitalA']->getId(), $hospitalDetails);

        self::assertCount(4, $this->provider->periodQuarterChoices(2025, 'en'));
        self::assertCount(12, $this->provider->periodMonthChoices(2025, 'en'));
        self::assertNotEmpty($this->provider->periodYearChoices());
    }

    public function testPublicScopeDoesNotRequireDetailChoices(): void
    {
        self::assertFalse($this->provider->scopeDetailRequired('public', null, StatisticsFilterSide::Primary));
        self::assertSame([], $this->provider->scopeDetailChoices('public', null, StatisticsFilterSide::Primary, 'en'));
    }
}
