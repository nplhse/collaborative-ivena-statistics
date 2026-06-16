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
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class CaseFlowControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;

    public function testCaseFlowPageShowsAggregatedKpisWithSeededData(): void
    {
        $client = self::createClient();

        $user = UserFactory::createOne(['username' => 'case-flow-ctrl-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'CaseFlowCtrlState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'CaseFlowCtrlDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'CaseFlowCtrlHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'CaseFlowCtrlSpec']);
        DepartmentFactory::createOne(['name' => 'CaseFlowCtrlDept']);
        AssignmentFactory::createOne(['name' => 'CaseFlowCtrlAssign']);
        IndicationRawFactory::createOne(['name' => 'CaseFlowCtrlRaw', 'code' => 912_502]);

        $import = ImportFactory::createOne(['name' => 'CaseFlowCtrlImport', 'hospital' => $hospital, 'createdBy' => $user]);
        AllocationFactory::createMany(15, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2026-04-01 09:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 09:30:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $this->loginAsRoleUser($client);
        $crawler = $client->request(Request::METHOD_GET, '/statistics/case-flow?scope=public&period=all');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-case-flow-kpis"]', '15');
        $this->assertSelectorTextContains('[data-testid="stats-case-flow-kpis"]', '100%');

        $payloadJson = (string) $crawler->filter('[data-controller="case-flow-charts"]')->attr('data-case-flow-charts-payload-value');
        $payload = json_decode(html_entity_decode($payloadJson, ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('system_flow', $payload['mode']);
        self::assertNotEmpty($payload['mapFeatures']);
        self::assertSame('caseflowctrldispatch', $payload['mapFeatures'][0]['geoKey']);
    }

    public function testCaseFlowPageIsDisplayedForPublicScope(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(Request::METHOD_GET, '/statistics/case-flow?scope=public&period=all');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-case-flow-heading-title"]');
        $this->assertSelectorExists('[data-testid="stats-case-flow-kpis"]');
        $this->assertSelectorExists('[data-testid="stats-case-flow-map"]');
    }

    public function testCaseFlowSystemModeShowsStackedBar(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(Request::METHOD_GET, '/statistics/case-flow?scope=public&period=all');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-case-flow-stacked-bar"]');
    }

    public function testCaseFlowPageDoesNotExposeForeignHospitalNames(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(Request::METHOD_GET, '/statistics/case-flow?scope=public&period=all');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('data-hospital-name', $content);
    }

    public function testRoleUserSeesPublicStateDispatchScopesOnly(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/case-flow?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $labels = $this->scopePrimaryMenuLabels($crawler);
        self::assertContains('Public', $labels);
        self::assertNotContains('My hospitals', $labels);
        self::assertNotContains('Hospitals', $labels);
    }

    public function testParticipantWithOwnedHospitalsSeesMyHospitalsLabel(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne(['owner' => $user]);
        HospitalFactory::createOne(['owner' => $user]);
        $client->loginUser($user->_real());

        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/case-flow?scope=my_hospitals&period=all',
        );

        $this->assertResponseIsSuccessful();
        $labels = $this->scopePrimaryMenuLabels($crawler);
        self::assertContains('My hospitals', $labels);
        self::assertSelectorExists('[data-testid="stats-case-flow-origin-bar"]');
        self::assertSelectorNotExists('[data-testid="stats-case-flow-stacked-bar"]');
    }

    public function testInvalidMyHospitalsScopeRedirectsToPublic(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->followRedirects(false);
        $client->request(
            Request::METHOD_GET,
            '/statistics/case-flow?scope=my_hospitals&period=all',
        );

        $this->assertResponseStatusCodeSame(302);
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('scope=public', $location);
        self::assertStringNotContainsString('my_hospitals', $location);
    }

    /**
     * @return list<string>
     */
    private function scopePrimaryMenuLabels(Crawler $crawler): array
    {
        return $crawler
            ->filter('.page-header .dropdown-menu .dropdown-item')
            ->each(static fn (Crawler $node): string => trim($node->text()));
    }
}
