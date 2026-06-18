<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\GenericAnalysis\Application\SavedAnalysisViewService;
use App\Statistics\Infrastructure\Repository\SavedAnalysisViewRepository;
use App\Tests\Support\Statistics\RefreshesStatisticsFunctionalDataTrait;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class SavedAnalysisViewControllerTest extends WebTestCase
{
    use Factories;
    use RefreshesStatisticsFunctionalDataTrait;

    public function testSaveViewFromCustomizeDrawerRedirectsToSavedView(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->seedProjectionWithAllocation();

        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/view/allocations_by_month?scope=public&period=all',
        );
        $this->assertResponseIsSuccessful();

        $client->submit($this->saveViewForm($crawler, 'My saved analysis'));
        $this->assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('/statistics/analytics/saved/', $location);

        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-analytics-view-title"]', 'My saved analysis');
        $this->assertSelectorExists('[data-testid="stats-generic-analysis-chart-card"]');
    }

    public function testSaveViewRequiresTitle(): void
    {
        $client = $this->createAuthenticatedClient();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/view/allocations_by_month?scope=public&period=all',
        );
        $this->assertResponseIsSuccessful();

        $client->submit($this->saveViewForm($crawler, ''));
        $this->assertResponseRedirects();

        $client->followRedirect();
        $this->assertResponseIsSuccessful();
    }

    public function testSavedViewNotFoundForOtherUser(): void
    {
        $client = $this->createAuthenticatedClient();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/view/allocations_by_month?scope=public&period=all',
        );
        $client->submit($this->saveViewForm($crawler, 'Owner only view'));
        $savedId = $this->extractSavedIdFromLocation((string) $client->getResponse()->headers->get('Location'));

        $otherUser = UserFactory::createOne([
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
            'username' => 'other-saved-view-user',
        ]);
        $client->loginUser($otherUser);
        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/saved/'.$savedId.'?scope=public&period=all',
        );

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeletedSavedViewIsNoLongerAccessible(): void
    {
        $client = $this->createAuthenticatedClient();
        $user = UserFactory::first();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/view/allocations_by_month?scope=public&period=all',
        );
        $client->submit($this->saveViewForm($crawler, 'Delete me'));
        $savedId = $this->extractSavedIdFromLocation((string) $client->getResponse()->headers->get('Location'));

        $saved = $client->getContainer()->get(SavedAnalysisViewRepository::class)->findForOwner($savedId, $user);
        self::assertNotNull($saved);
        $client->getContainer()->get(SavedAnalysisViewService::class)->delete($saved);

        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/saved/'.$savedId.'?scope=public&period=all',
        );
        $this->assertResponseStatusCodeSame(404);
    }

    private function createAuthenticatedClient(): KernelBrowser
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $client->loginUser($user);

        return $client;
    }

    private function saveViewForm(Crawler $crawler, string $title): \Symfony\Component\DomCrawler\Form
    {
        $form = $crawler->filter('[data-testid="stats-analytics-save-view"]')->form();
        $form['title'] = $title;

        return $form;
    }

    private function extractSavedIdFromLocation(string $location): int
    {
        if (!preg_match('#/statistics/analytics/saved/(\d+)#', $location, $matches)) {
            self::fail('Saved view redirect did not contain an id.');
        }

        return (int) $matches[1];
    }

    private function seedProjectionWithAllocation(): void
    {
        $user = UserFactory::createOne(['username' => 'saved-analysis-view-test']);
        $state = StateFactory::createOne(['name' => 'Saved View State', 'createdBy' => $user]);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'Saved View Dispatch']);
        $hospital = HospitalFactory::createOne(['name' => 'Saved View Hospital']);
        $import = ImportFactory::createOne(['name' => 'Saved View Import', 'hospital' => $hospital, 'createdBy' => $user]);
        SpecialityFactory::createOne(['name' => 'Saved View Speciality']);
        DepartmentFactory::createOne(['name' => 'Saved View Department']);
        AssignmentFactory::createOne(['name' => 'Saved View Assignment']);
        OccasionFactory::createOne(['name' => 'Saved View Occasion']);
        InfectionFactory::createOne(['name' => 'Saved View Infection']);
        $raw = IndicationRawFactory::createOne(['name' => 'Saved View Raw']);
        $normalized = IndicationNormalizedFactory::createOne(['name' => 'Saved View Normalized']);
        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('today'),
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationRaw' => $raw,
            'indicationNormalized' => $normalized,
        ]);

        $this->rebuildProjectionForImports([(int) $import->getId()]);
    }
}
