<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Controller;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\UI\Http\Controller\StatisticsPageViewModelFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class StatisticsPageViewModelFactoryAccessTest extends KernelTestCase
{
    private StatisticsPageViewModelFactory $factory;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->factory = self::getContainer()->get(StatisticsPageViewModelFactory::class);
    }

    public function testUserWithoutParticipantHasNoMyHospitalsMenuEntry(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);
        $model = $this->factory->create(
            new Request(query: ['scope' => 'public', 'period' => 'all']),
            'app_stats_dashboard',
            $user,
            new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::All),
        );

        self::assertFalse($this->hasMenuKey($model->scopePrimaryMenu, 'my_hospitals_group'));
    }

    public function testParticipantSeesMyHospitalsLabel(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne(['owner' => $user]);
        HospitalFactory::createOne(['owner' => $user]);

        $model = $this->factory->create(
            new Request(query: ['scope' => 'my_hospitals', 'period' => 'all']),
            'app_stats_dashboard',
            $user,
            new StatisticsFilter(StatisticsFilterScope::MyHospitals, null, null, StatisticsFilterPeriod::All),
        );

        self::assertTrue($this->hasMenuKey($model->scopePrimaryMenu, 'my_hospitals_group'));
        self::assertSame('My hospitals', $this->menuLabel($model->scopePrimaryMenu, 'my_hospitals_group'));
    }

    public function testAdminSeesHospitalsLabel(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createMany(2);

        $model = $this->factory->create(
            new Request(query: ['scope' => 'my_hospitals', 'period' => 'all']),
            'app_stats_dashboard',
            $user,
            new StatisticsFilter(StatisticsFilterScope::MyHospitals, null, null, StatisticsFilterPeriod::All),
        );

        self::assertTrue($this->hasMenuKey($model->scopePrimaryMenu, 'my_hospitals_group'));
        self::assertSame('Hospitals', $this->menuLabel($model->scopePrimaryMenu, 'my_hospitals_group'));
    }

    public function testDualRoleUserSeesHospitalsLabelAndAllHospitalsInSecondary(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createMany(3);

        $model = $this->factory->create(
            new Request(query: ['scope' => 'my_hospitals', 'period' => 'all']),
            'app_stats_dashboard',
            $user,
            new StatisticsFilter(StatisticsFilterScope::MyHospitals, null, null, StatisticsFilterPeriod::All),
        );

        self::assertSame('Hospitals', $this->menuLabel($model->scopePrimaryMenu, 'my_hospitals_group'));
        self::assertTrue($model->showScopeSecondaryPicker);
        self::assertGreaterThanOrEqual(3, \count($model->scopeSecondaryMenu));
    }

    public function testUserWithoutParticipantHasNoMyHospitalsMenuEntryOnAnalyticsLibraryRoute(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);
        $model = $this->factory->create(
            new Request(query: ['scope' => 'public', 'period' => 'all']),
            'app_stats_analytics_library',
            $user,
            new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::All),
        );

        self::assertFalse($this->hasMenuKey($model->scopePrimaryMenu, 'my_hospitals_group'));
    }

    public function testShowsUnscopedHintForParticipantWithoutLinkedHospitalsOnMyHospitalsScope(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);

        $model = $this->factory->create(
            new Request(query: ['scope' => 'my_hospitals', 'period' => 'all']),
            'app_stats_dashboard',
            $user,
            new StatisticsFilter(StatisticsFilterScope::MyHospitals, null, null, StatisticsFilterPeriod::All),
        );

        self::assertTrue($model->showUnscopedHint);
        self::assertSame('Public', $model->headingScope);
    }

    /**
     * @param list<array{key: string, label: string, url: string, active: bool}> $menu
     */
    private function hasMenuKey(array $menu, string $key): bool
    {
        return array_any($menu, fn ($item): bool => $item['key'] === $key);
    }

    /**
     * @param list<array{key: string, label: string, url: string, active: bool}> $menu
     */
    private function menuLabel(array $menu, string $key): string
    {
        foreach ($menu as $item) {
            if ($item['key'] === $key) {
                return $item['label'];
            }
        }

        throw new \RuntimeException(sprintf('Menu key "%s" not found.', $key));
    }
}
