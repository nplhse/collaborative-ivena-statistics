<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\WithoutEdVisit;

use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Kernel\KernelWithoutEncounterSchemas;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The application without any encounter schema (#1300 step 5).
 * The encounter import and the data preparation tool are hidden; grants and keys stay manageable.
 */
#[ResetDatabase]
final class NoEncounterSchemaTest extends WebTestCase
{
    use EncounterTestFixtures;
    use Factories;
    use NoMissingRouteLinks;

    #[\Override]
    protected static function getKernelClass(): string
    {
        return KernelWithoutEncounterSchemas::class;
    }

    public function testTheEncounterImportAndTheDataPrepToolAreHidden(): void
    {
        $client = self::createClient();
        $user = $this->user();
        $hospital = $this->hospital($user);
        $this->encounterGrant($hospital, $user, [EncounterGrantAction::Upload, EncounterGrantAction::Review]);
        $client->loginUser($user);

        $crawler = $client->request(Request::METHOD_GET, '/import/new');
        self::assertResponseIsSuccessful();
        // Without schemas the encounter type is not offered; allocations and closure lists remain (#1350).
        self::assertSame(
            ['Allocation', 'Closure'],
            $crawler->filter('select[name="import_create[type]"] option')->each(static fn ($option): string => (string) $option->attr('data-import-type')),
        );
        self::assertCount(0, $crawler->filter('select[name="import_create[encounterSchema]"]'));

        $client->request(Request::METHOD_GET, '/tools/data-prep');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $crawler = $client->request(Request::METHOD_GET, '/tools');
        self::assertCount(0, $crawler->filter('a[href="/tools/data-prep"]'));

        foreach (['encounter-access', 'encounter-key'] as $page) {
            $client->request(Request::METHOD_GET, '/hospitals/'.$hospital->getId().'/edit/'.$page);
            self::assertResponseIsSuccessful($page);
        }
    }

    public function testNoMenuEntryPointsToAMissingRoute(): void
    {
        $client = self::createClient();
        $admin = $this->user(['ROLE_USER', 'ROLE_ADMIN', 'ROLE_PARTICIPANT', 'ROLE_ENCOUNTER_BETA', 'ROLE_ENCOUNTER_CATALOG_REVIEWER']);
        $this->hospital($admin);
        $client->loginUser($admin);

        $this->assertNoLinkToAMissingRoute($client, ['/', '/tools', '/explore', '/hospitals', '/admin', '/import/new']);
    }
}
