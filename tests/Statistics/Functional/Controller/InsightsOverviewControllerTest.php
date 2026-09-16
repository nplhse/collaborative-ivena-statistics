<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationGroupFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class InsightsOverviewControllerTest extends WebTestCase
{
    use Factories;

    public function testOverviewRendersSearchFeaturedAndTeasersWithoutOpeningADetail(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'insights-overview-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $state = StateFactory::createOne(['name' => 'InsightsOverviewState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'InsightsOverviewDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'InsightsOverviewHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'InsightsOverviewSpec']);
        DepartmentFactory::createOne(['name' => 'InsightsOverviewDept']);
        AssignmentFactory::createOne(['name' => 'InsightsOverviewAssign']);
        IndicationRawFactory::createOne(['name' => 'InsightsOverviewRaw', 'code' => 912_351]);
        $indication = IndicationNormalizedFactory::createOne(['name' => 'Overview Test Indication', 'code' => 1002]);

        $import = ImportFactory::createOne(['name' => 'InsightsOverviewImport', 'hospital' => $hospital, 'createdBy' => $user]);
        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'age' => 42,
            'indicationNormalized' => $indication,
            'createdAt' => new \DateTimeImmutable('2026-04-01 14:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 14:25:00'),
        ]);
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights', [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'all',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="stats-insights-overview-title"]');
        self::assertSelectorExists('[data-testid="stats-insights-search"]');
        self::assertSelectorExists('[data-testid="stats-insights-subnav"]');
        self::assertSelectorExists('[data-testid="stats-insights-tab-overview"]');
        self::assertSelectorExists('[data-testid="stats-insights-tab-indications"]');
        self::assertSelectorExists('[data-testid="stats-insights-tab-specialities"]');
        self::assertSelectorExists('[data-testid="stats-insights-tab-assignments"]');
        self::assertSelectorExists('[data-testid="stats-insights-tab-departments"]');
        self::assertSelectorExists('[data-testid="stats-insights-tab-occasions"]');
        self::assertSelectorExists('[data-testid="stats-insights-tab-infections"]');
        self::assertSelectorExists('[data-testid="stats-insights-tab-secondary-transports"]');
        self::assertSelectorNotExists('[data-testid="stats-insights-tab-more"]');
        self::assertSelectorExists('[data-testid="stats-insights-featured"]');
        self::assertSelectorExists('[data-testid="stats-insights-teasers"]');
        self::assertSelectorNotExists('[data-testid="stats-indication-heading-title"]');
        self::assertSelectorTextContains('[data-testid="stats-insights-featured"]', 'Overview Test Indication');
        self::assertSelectorExists(sprintf('a[href*="/statistics/insights/indications/%d"]', $indication->getId()));
        self::assertSelectorExists('[data-testid="stats-insights-featured-groups"]');
        self::assertSelectorExists('[data-testid="stats-insights-featured-all"]');
        self::assertSelectorExists('[data-testid="stats-insights-featured"] .card-footer .btn');
        $topListHref = $client->getCrawler()->filter('[data-testid="stats-insights-featured-top-list"]')->attr('href');
        self::assertNotNull($topListHref);
        self::assertStringContainsString('/statistics/top-lists/top_diagnoses', $topListHref);
        self::assertStringContainsString('scope=hospital', $topListHref);
        self::assertSelectorExists('[data-testid="stats-insights-teaser-title-specialities"]');
        $specialityTitle = $client->getCrawler()->filter('[data-testid="stats-insights-teaser-title-specialities"]');
        $specialityHref = $specialityTitle->attr('href');
        self::assertNotNull($specialityHref);
        self::assertStringContainsString('/statistics/insights/specialities', $specialityHref);
        self::assertStringContainsString('link-primary', (string) $specialityTitle->attr('class'));
        self::assertSelectorNotExists('[data-testid="stats-insights-teaser-all-specialities"]');
        self::assertSelectorExists('[data-testid="stats-data-quality-indicator"]');
        self::assertSelectorExists('[data-testid="stats-data-quality-drawer"]');
    }

    public function testOverviewFeaturedGroupsLinkOpensGroupsDirectory(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'insights-overview-groups-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $indication = IndicationNormalizedFactory::createOne(['name' => 'Group Member Indication']);
        $group = IndicationGroupFactory::createOne(['name' => 'Insights Overview Group', 'createdBy' => $user]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $groupEntity = $entityManager->find(\App\Allocation\Domain\Entity\IndicationGroup::class, $group->getId());
        self::assertNotNull($groupEntity);
        $groupEntity->addIndication($indication);
        $entityManager->flush();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights', [
            'scope' => 'public',
            'period' => 'all',
        ]);

        self::assertResponseIsSuccessful();
        $groupsHref = $client->getCrawler()->filter('[data-testid="stats-insights-featured-groups-all"]')->attr('href');
        self::assertNotNull($groupsHref);
        self::assertStringContainsString('/statistics/insights/indications', $groupsHref);
        self::assertStringContainsString('view=groups', $groupsHref);
    }
}
