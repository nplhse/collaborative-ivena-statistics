<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Tests\Functional\Controller\DataCatalog;

use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The ED visit page of the Explore data catalog (#1415): a plugin object, visible only with encounter access.
 */
#[ResetDatabase]
final class EdVisitDataCatalogPageTest extends WebTestCase
{
    use Factories;

    public function testParticipantWithoutEncounterAccessDoesNotSeeTheEdVisitObject(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]));

        $client->request(Request::METHOD_GET, '/explore/data-catalog');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-object-key="ed_visit"]');

        $client->request(Request::METHOD_GET, '/explore/data-catalog/ed_visit');
        self::assertResponseStatusCodeSame(404);
    }

    public function testUserWithEncounterAccessSeesThePluginObject(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_PARTICIPANT', 'ROLE_ENCOUNTER_BETA']]));

        $client->request(Request::METHOD_GET, '/explore/data-catalog');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-object-key="ed_visit"]', '43 variables');
        self::assertSelectorTextContains('[data-object-key="ed_visit"]', 'Plugin');

        $client->request(Request::METHOD_GET, '/explore/data-catalog/ed_visit');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#ed-visit-mts-category [data-testid="data-catalog-codes"]', 'red');
        self::assertSelectorTextContains('#ed-visit-isolation', 'Enum set');
        self::assertSelectorTextContains('#ed-visit-isolation [data-testid="data-catalog-codes"]', 'Tuberculosis');
        self::assertSelectorTextContains('#ed-visit-isolation [data-testid="data-catalog-storage-type"]', 'jsonb');
        self::assertSelectorTextContains('#ed-visit-disposition [data-testid="data-catalog-codes-toggle"] summary', '9 codes');
        self::assertSelectorTextContains('#ed-visit-temperature [data-testid="data-catalog-storage-type"]', 'numeric(4, 1)');
        self::assertSelectorTextContains('#ed-visit-temperature [data-testid="data-catalog-range"]', '°C');
        self::assertSelectorTextContains('#ed-visit-ed-admission-at [data-testid="data-catalog-storage"]', 'ed_visit.ed_admission_at');
        self::assertSelectorExists('#ed-visit-encounter a[href="/explore/data-catalog/encounter"]');
        self::assertSelectorExists('#ed-visit-mts-category a[data-testid="data-catalog-glossary-link"][href="/explore/glossary/mts#categories"]');
        self::assertSelectorExists('#ed-visit-retriage-diagram a[data-testid="data-catalog-glossary-link"][href="/explore/glossary/mts#diagrams"]');
        self::assertSelectorExists('#ed-visit-mts-indicator a[data-testid="data-catalog-glossary-link"][href="/explore/glossary/mts#indicators"]');
        self::assertSelectorCount(6, '[data-testid="data-catalog-glossary-link"]');
        self::assertSelectorCount(9, '[data-testid="data-catalog-group"] .card-header a[href="#top"]');
        self::assertSelectorTextNotContains('body', 'data_catalog.');
        self::assertSelectorTextNotContains('body', 'encounter.mts.');
    }
}
