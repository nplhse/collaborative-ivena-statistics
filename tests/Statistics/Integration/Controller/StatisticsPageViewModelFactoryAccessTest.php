<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Controller;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\UI\Http\Controller\StatisticsPageViewModelFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Symfony\Component\HttpFoundation\Request;

final class StatisticsPageViewModelFactoryAccessTest extends DatabaseKernelTestCase
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

    public function testUserWithoutParticipantHasNoMyHospitalsMenuEntryOnAnalysisLibraryRoute(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);
        $model = $this->factory->create(
            new Request(query: ['scope' => 'public', 'period' => 'all']),
            'app_stats_analysis_library',
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

    public function testParticipantSeesOnlyParticipatingHospitalsInAccessibleList(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $participating = HospitalFactory::createOne(['owner' => $user]);
        $nonParticipating = HospitalFactory::createOne(['owner' => $user]);
        $nonParticipating->setIsParticipating(false);
        \Zenstruck\Foundry\Persistence\save($nonParticipating);

        $model = $this->factory->create(
            new Request(query: ['scope' => 'my_hospitals', 'period' => 'all']),
            'app_stats_dashboard',
            $user,
            new StatisticsFilter(StatisticsFilterScope::MyHospitals, null, null, StatisticsFilterPeriod::All),
        );

        self::assertCount(1, $model->accessibleHospitals);
        self::assertSame((int) $participating->getId(), $model->accessibleHospitals[0]['id']);
    }

    public function testAdminSeesOnlyParticipatingHospitalsInAccessibleList(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $participating = HospitalFactory::createOne();
        $nonParticipating = HospitalFactory::createOne();
        $nonParticipating->setIsParticipating(false);
        \Zenstruck\Foundry\Persistence\save($nonParticipating);

        $model = $this->factory->create(
            new Request(query: ['scope' => 'my_hospitals', 'period' => 'all']),
            'app_stats_dashboard',
            $user,
            new StatisticsFilter(StatisticsFilterScope::MyHospitals, null, null, StatisticsFilterPeriod::All),
        );

        $ids = array_column($model->accessibleHospitals, 'id');
        self::assertContains((int) $participating->getId(), $ids);
        self::assertNotContains((int) $nonParticipating->getId(), $ids);
    }

    /**
     * @param list<array{key: string, label: string, url: string, active: bool}> $menu
     */
    private function hasMenuKey(array $menu, string $key): bool
    {
        return array_any($menu, fn (array $item): bool => $item['key'] === $key);
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
