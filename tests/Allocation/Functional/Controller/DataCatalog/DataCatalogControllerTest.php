<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\DataCatalog;

use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class DataCatalogControllerTest extends WebTestCase
{
    use InteractsWithAuthenticatedUser;
    use Factories;

    public function testOverviewListsTheCoreObjects(): void
    {
        $client = $this->createClientAsParticipant();
        $client->request(Request::METHOD_GET, '/explore/data-catalog');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="data-catalog-object"][data-object-key="allocation"]');
        self::assertSelectorTextContains('[data-object-key="allocation"]', 'Allocation');
        self::assertSelectorTextContains('[data-object-key="allocation"]', '32 variables');
        self::assertSelectorTextContains('[data-object-key="mci_case"]', '25 variables');
        self::assertSelectorTextContains('[data-object-key="hospital"]', '13 variables');
        self::assertSelectorTextContains('[data-object-key="dispatch_area"]', '2 variables');
        self::assertSelectorTextContains('[data-object-key="state"]', '1 variable');
        self::assertSelectorTextContains('[data-object-key="indication_normalized"]', '2 variables');
        self::assertSelectorTextContains('[data-object-key="indication_group"]', '2 variables');
        foreach (['speciality', 'department', 'assignment', 'infection', 'occasion', 'secondary_transport'] as $key) {
            self::assertSelectorTextContains(sprintf('[data-object-key="%s"]', $key), '1 variable');
        }
    }

    public function testReferenceToADocumentedObjectLinksToItsEntry(): void
    {
        $client = $this->createClientAsParticipant();
        $client->request(Request::METHOD_GET, '/explore/data-catalog/allocation');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#allocation-hospital a[href="/explore/data-catalog/hospital"]');
        self::assertSelectorExists('#allocation-dispatch-area a[href="/explore/data-catalog/dispatch_area"]');
        self::assertSelectorExists('#allocation-state a[href="/explore/data-catalog/state"]');
        self::assertSelectorExists('#allocation-indication-normalized a[href="/explore/data-catalog/indication_normalized"]');
        self::assertSelectorExists('#allocation-assignment a[href="/explore/data-catalog/assignment"]');
        self::assertSelectorExists('#allocation-secondary-transport a[href="/explore/data-catalog/secondary_transport"]');
    }

    public function testEveryReferenceOfTheCoreObjectsLinksToAnEntryExceptRawIndications(): void
    {
        $client = $this->createClientAsParticipant();

        $expected = [
            'allocation' => ['allocation.indication_raw', 'allocation.secondary_indication_raw'],
            'mci_case' => ['mci_case.indication_raw'],
            'hospital' => [],
        ];

        foreach ($expected as $object => $rawReferences) {
            $crawler = $client->request(Request::METHOD_GET, '/explore/data-catalog/'.$object);
            self::assertResponseIsSuccessful();

            $unlinked = $crawler->filterXPath('//tr[@data-variable-key][.//span[normalize-space(.)="Reference to"]]')->each(
                static fn (Crawler $row): string => (string) $row->attr('data-variable-key'),
            );
            self::assertSame($rawReferences, $unlinked, $object);
        }
    }

    public function testRegionDetailsShowTheirVariablesAndLinkTheirParent(): void
    {
        $client = $this->createClientAsParticipant();
        $client->request(Request::METHOD_GET, '/explore/data-catalog/dispatch_area');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2.page-title', 'Dispatch area');
        self::assertSelectorTextContains('#dispatch-area-name [data-testid="data-catalog-storage"]', 'dispatch_area.name');
        self::assertSelectorExists('#dispatch-area-state a[href="/explore/data-catalog/state"]');
        self::assertSelectorTextNotContains('body', 'data_catalog.');

        $client->request(Request::METHOD_GET, '/explore/data-catalog/state');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#state-name [data-testid="data-catalog-storage"]', 'state.name');
    }

    public function testHospitalDetailShowsPrefixedCodeLabelsAndEmbeddedColumns(): void
    {
        $client = $this->createClientAsParticipant();
        $client->request(Request::METHOD_GET, '/explore/data-catalog/hospital');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#hospital-size [data-testid="data-catalog-codes"]', 'Small');
        self::assertSelectorTextContains('#hospital-postal-code [data-testid="data-catalog-storage"]', 'hospital.address_postal_code');
        self::assertSelectorTextNotContains('body', 'data_catalog.');
        self::assertSelectorTextNotContains('body', 'hospital.size.');
    }

    public function testDetailShowsGroupedVariablesWithStorageCodesAndRange(): void
    {
        $client = $this->createClientAsParticipant();
        $client->request(Request::METHOD_GET, '/explore/data-catalog/allocation');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="data-catalog-group"][data-group-key="clinical"]');
        self::assertSelectorExists('tr#allocation-urgency[data-variable-key="allocation.urgency"]');
        self::assertSelectorTextContains('#allocation-urgency [data-testid="data-catalog-storage"]', 'allocation.urgency');
        self::assertSelectorTextContains('#allocation-urgency [data-testid="data-catalog-codes"]', 'Emergency');
        self::assertSelectorTextContains('#allocation-age [data-testid="data-catalog-range"]', '99');
        self::assertSelectorTextContains('#allocation-assessment-airway [data-testid="data-catalog-storage"]', 'assessment.airway');
        self::assertSelectorTextNotContains('body', 'data_catalog.');
    }

    public function testGermanLocaleShowsGermanLabels(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'locale' => 'de']));
        $client->request(Request::METHOD_GET, '/explore/data-catalog/allocation');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2.page-title', 'Zuweisung');
        self::assertSelectorTextContains('#allocation-urgency', 'Dringlichkeit');
        self::assertSelectorTextContains('#allocation-age [data-testid="data-catalog-range"]', 'Jahre');
    }

    public function testUnknownObjectReturnsNotFound(): void
    {
        $client = $this->createClientAsParticipant();
        $client->request(Request::METHOD_GET, '/explore/data-catalog/unknown');

        self::assertResponseStatusCodeSame(404);
    }

    public function testExploreOverviewLinksToTheCatalog(): void
    {
        $client = $this->createClientAsParticipant();
        $client->request(Request::METHOD_GET, '/explore');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="data-catalog-tile"]');
        self::assertSelectorExists('a[href="/explore/data-catalog"]');
    }

    public function testCatalogPagesQueryNoCaseData(): void
    {
        $client = $this->createClientAsParticipant();

        foreach (['/explore/data-catalog', '/explore/data-catalog/allocation', '/explore/data-catalog/mci_case', '/explore/data-catalog/hospital', '/explore/data-catalog/dispatch_area', '/explore/data-catalog/state', '/explore/data-catalog/indication_normalized', '/explore/data-catalog/indication_group'] as $url) {
            self::assertSame([], $this->caseDataQueries($client, $url), $url);
        }
    }

    /**
     * @return list<string>
     */
    private function caseDataQueries(KernelBrowser $client, string $url): array
    {
        $client->enableProfiler();
        $client->request(Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();

        $profile = $client->getProfile();
        self::assertNotFalse($profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        $matches = [];
        foreach ($collector->getQueries() as $queries) {
            foreach ($queries as $query) {
                $sql = (string) $query['sql'];
                if (1 === preg_match('/\b(FROM|JOIN)\s+"?(allocation|assessment|mci_case|encounter|ed_visit)"?\b/i', $sql)) {
                    $matches[] = $sql;
                }
            }
        }

        return $matches;
    }
}
