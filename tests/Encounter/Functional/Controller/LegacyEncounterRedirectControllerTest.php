<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\Controller;

use App\Tests\Support\Encounter\EncounterTestFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Old encounter URLs keep working until v0.4-beta (#1128, removal in #1160).
 */
#[ResetDatabase]
final class LegacyEncounterRedirectControllerTest extends WebTestCase
{
    use EncounterTestFixtures;
    use Factories;

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function legacyPaths(): iterable
    {
        yield 'overview' => [Request::METHOD_GET, '/encounter', '/tools'];
        yield 'hospital list' => [Request::METHOD_GET, '/encounter/review', '/tools/encounter-review'];
        yield 'worklist with period' => [Request::METHOD_GET, '/encounter/review/7?from=2026-10-01&to=2026-10-31', '/tools/encounter-review/7?from=2026-10-01&to=2026-10-31'];
        yield 'review overview' => [Request::METHOD_GET, '/encounter/review/7/overview', '/tools/encounter-review/7/overview'];
        yield 'unlinked visits' => [Request::METHOD_GET, '/encounter/review/7/visits', '/tools/encounter-review/7/visits'];
        yield 'start' => [Request::METHOD_GET, '/encounter/review/7/start', '/tools/encounter-review/7/start'];
        yield 'allocation' => [Request::METHOD_GET, '/encounter/review/7/allocations/12', '/tools/encounter-review/7/allocations/12'];
        yield 'allocation decision' => [Request::METHOD_POST, '/encounter/review/7/allocations/12', '/tools/encounter-review/7/allocations/12'];
        yield 'skip' => [Request::METHOD_GET, '/encounter/review/7/allocations/12/skip', '/tools/encounter-review/7/allocations/12/skip'];
        yield 'swap' => [Request::METHOD_POST, '/encounter/review/7/allocations/12/swap', '/tools/encounter-review/7/allocations/12/swap'];
        yield 'validation' => [Request::METHOD_GET, '/encounter/review/7/validation', '/tools/encounter-review/7/validation'];
        yield 'new sample' => [Request::METHOD_POST, '/encounter/review/7/validation', '/tools/encounter-review/7/validation'];
        yield 'sample' => [Request::METHOD_GET, '/encounter/review/7/validation/3', '/tools/encounter-review/7/validation/3'];
        yield 'item' => [Request::METHOD_POST, '/encounter/review/7/validation/3/items/5', '/tools/encounter-review/7/validation/3/items/5'];
        yield 'data prep' => [Request::METHOD_GET, '/encounter/data-prep', '/tools/data-prep'];
        yield 'key' => [Request::METHOD_GET, '/encounter/data-prep/key', '/tools/data-prep/key'];
        yield 'local map' => [Request::METHOD_GET, '/encounter/data-prep/local-map', '/tools/data-prep/local-map'];
        yield 'local map file' => [Request::METHOD_GET, '/encounter/data-prep/local-map-template.json', '/tools/data-prep/local-map-template.json'];
        yield 'download' => [Request::METHOD_GET, '/encounter/data-prep/download', '/tools/data-prep/download'];
    }

    #[DataProvider('legacyPaths')]
    public function testLegacyPathRedirectsPermanently(string $method, string $legacyPath, string $target): void
    {
        $client = self::createClient();
        $client->loginUser($this->user());

        $client->request($method, $legacyPath);

        self::assertResponseStatusCodeSame(Response::HTTP_PERMANENTLY_REDIRECT);
        self::assertResponseRedirects($target);
    }

    public function testUpdateCheckOfTheDownloadedToolStaysWhereItIs(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/data-prep/latest.json');

        self::assertResponseIsSuccessful();
    }

    public function testLegacyRouteNamesStillGenerateTheOldPaths(): void
    {
        self::bootKernel();
        $urlGenerator = self::getContainer()->get(UrlGeneratorInterface::class);

        self::assertSame('/encounter', $urlGenerator->generate('app_encounter_index'));
        self::assertSame('/encounter/review/7/validation', $urlGenerator->generate('app_encounter_validation', ['id' => 7]));
        self::assertSame('/encounter/data-prep', $urlGenerator->generate('app_encounter_data_prep'));
    }
}
