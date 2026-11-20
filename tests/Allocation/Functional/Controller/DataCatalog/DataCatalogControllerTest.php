<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\DataCatalog;

use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class DataCatalogControllerTest extends WebTestCase
{
    use InteractsWithAuthenticatedUser;
    use Factories;

    public function testOverviewListsTheAllocationObject(): void
    {
        $client = $this->createClientAsParticipant();
        $client->request(Request::METHOD_GET, '/explore/data-catalog');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="data-catalog-object"][data-object-key="allocation"]');
        self::assertSelectorTextContains('[data-object-key="allocation"]', 'Allocation');
        self::assertSelectorTextContains('[data-object-key="allocation"]', '32 variables');
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

        foreach (['/explore/data-catalog', '/explore/data-catalog/allocation'] as $url) {
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
                if (1 === preg_match('/\b(FROM|JOIN)\s+"?(allocation|assessment|encounter|ed_visit)"?\b/i', $sql)) {
                    $matches[] = $sql;
                }
            }
        }

        return $matches;
    }
}
