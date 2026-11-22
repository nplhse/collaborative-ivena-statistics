<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\DataCatalog;

use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The encounter object of the Explore data catalog (#1415) is shown only to users who may see cases
 * of any hospital (EncounterAttribute::VIEW_CASES_IN_ANY_HOSPITAL).
 */
#[ResetDatabase]
final class EncounterDataCatalogTest extends WebTestCase
{
    use Factories;

    public function testParticipantWithoutEncounterAccessDoesNotSeeTheEncounterObject(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]));

        $client->request(Request::METHOD_GET, '/explore/data-catalog');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-object-key="allocation"]');
        self::assertSelectorNotExists('[data-object-key="encounter"]');

        $client->request(Request::METHOD_GET, '/explore/data-catalog/encounter');
        self::assertResponseStatusCodeSame(404);
    }

    public function testUserWithEncounterAccessSeesTheEncounterObject(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_PARTICIPANT', 'ROLE_ENCOUNTER_BETA']]));

        $client->request(Request::METHOD_GET, '/explore/data-catalog');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-object-key="encounter"]', '8 variables');

        $client->request(Request::METHOD_GET, '/explore/data-catalog/encounter');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#encounter-sex [data-testid="data-catalog-codes"]', 'female');
        self::assertSelectorTextContains('#encounter-age [data-testid="data-catalog-range"]', 'years');
        self::assertSelectorExists('#encounter-allocation a[href="/explore/data-catalog/allocation"]');
        self::assertSelectorTextNotContains('body', 'data_catalog.');
    }
}
