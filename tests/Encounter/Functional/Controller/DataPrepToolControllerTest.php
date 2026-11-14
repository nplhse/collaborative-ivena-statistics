<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\Controller;

use App\Encounter\Application\Catalog\MtsCatalogCuration;
use App\Encounter\Application\Catalog\MtsCatalogFirstSeenWriter;
use App\Encounter\Domain\Entity\MtsAlias;
use App\Encounter\Domain\Entity\MtsDiagram;
use App\Encounter\Domain\Enum\MtsCatalogKind;
use App\Encounter\Domain\Enum\MtsCatalogSource;
use App\Encounter\Domain\Enum\MtsCatalogStatus;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class DataPrepToolControllerTest extends WebTestCase
{
    use EncounterTestFixtures;
    use Factories;
    use InteractsWithAuthenticatedUser;

    public function testLatestVersionIsPublicWithoutCookies(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/data-prep/latest.json', server: ['HTTP_ORIGIN' => 'null']);

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame([], $response->headers->getCookies(), 'The update check must not set cookies.');
        self::assertFalse($response->headers->has('Set-Cookie'));
        self::assertSame([
            'version' => '1.2.0',
            'minVersion' => '1.0.0',
            'releasedAt' => '2026-11-14',
            'downloadUrl' => 'http://localhost/tools/data-prep/download',
        ], json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR));
    }

    /**
     * The public MTS catalog for the tool (#1157): the seed (40 diagrams, 70 indicators), a provisional entry, an
     * alias, and a merged entry that is listed only as an alias. Read only, no session, cacheable.
     */
    public function testMtsCatalogIsPublicWithoutCookies(): void
    {
        $client = self::createClient();
        $container = self::getContainer();
        $container->get(MtsCatalogFirstSeenWriter::class)->record(MtsCatalogKind::Diagram, ['zz_neu' => new \DateTimeImmutable('2026-11-14 09:00')]);
        $curation = $container->get(MtsCatalogCuration::class);
        $curation->createAlias(new MtsAlias(MtsCatalogKind::Diagram, '', 'Brust Schmerzen', 'thoraxschmerz'));
        $merged = new MtsDiagram('', 'Thorax Schmerz', MtsCatalogSource::Manual, MtsCatalogStatus::Provisional);
        $curation->createEntry($merged);
        $thorax = $this->entityManager()->getRepository(MtsDiagram::class)->findOneBy(['code' => 'thoraxschmerz']);
        self::assertInstanceOf(MtsDiagram::class, $thorax);
        $curation->merge($merged, $thorax);

        $client->request(Request::METHOD_GET, '/data-prep/mts-catalog.json', server: ['HTTP_ORIGIN' => 'null']);

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame([], $response->headers->getCookies(), 'The catalog must not set cookies.');
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame('300', $response->headers->getCacheControlDirective('max-age'));
        $etag = $response->getEtag();
        self::assertNotNull($etag);

        /** @var array{format: string, version: int, id: string, diagrams: list<array{code: string, name: string, englishName: ?string}>, indicators: list<array<string, mixed>>, aliases: array{diagrams: array<string, string>, indicators: list<string>|array<string, string>}} $catalog */
        $catalog = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['encounter-mts-catalog', 1, 'mts-de'], [$catalog['format'], $catalog['version'], $catalog['id']]);
        self::assertCount(41, $catalog['diagrams'], '40 seeded + the provisional entry; the merged entry is no entry');
        self::assertCount(70, $catalog['indicators']);
        self::assertContains(['code' => 'thoraxschmerz', 'name' => 'Thoraxschmerz', 'englishName' => 'Chest pain'], $catalog['diagrams']);
        self::assertContains(['code' => 'zz_neu', 'name' => 'zz_neu', 'englishName' => null], $catalog['diagrams']);
        self::assertSame(['brust_schmerzen' => 'thoraxschmerz', 'thorax_schmerz' => 'thoraxschmerz'], $catalog['aliases']['diagrams']);
        self::assertSame([], $catalog['aliases']['indicators']);

        $client->request(Request::METHOD_GET, '/data-prep/mts-catalog.json', server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_MODIFIED);
    }

    public function testDownloadNeedsTheBetaRole(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/tools/data-prep/download');
        self::assertResponseRedirects();

        $client->loginUser($this->user(['ROLE_USER', 'ROLE_PARTICIPANT']));
        $client->request(Request::METHOD_GET, '/tools/data-prep/download');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testBetaUserDownloadsTheSelfContainedTool(): void
    {
        $client = self::createClient();
        $client->loginUser($this->user());
        $client->request(Request::METHOD_GET, '/tools/data-prep/download');

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertSame('attachment; filename=encounter-data-prep-1.2.0.html', $response->headers->get('Content-Disposition'));
        $html = (string) $response->getContent();

        self::assertStringContainsString(
            "content=\"default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; connect-src http://localhost/data-prep/latest.json http://localhost/data-prep/mts-catalog.json;",
            str_replace('&#039;', "'", $html),
        );
        self::assertStringContainsString('globalThis.EncounterDataPrep', $html, 'core script is inlined');
        self::assertStringContainsString('"id":"orbis-mts"', $html, 'public profile is bundled');
        self::assertStringContainsString('"name":"visit_fingerprint"', $html, 'field contract is bundled');
        self::assertStringContainsString('"mts-de":{"format":"encounter-mts-catalog","version":1,"id":"mts-de"', $html, 'the bundled MTS catalog is the offline fallback (#1157)');
        self::assertStringContainsString('"catalogUrl":"http://localhost/data-prep/mts-catalog.json"', $html);
        self::assertStringContainsString('"catalogCounts":"diagrams: {diagrams}, indicators: {indicators}, aliases: {aliases}."', $html, 'placeholders are filled in by the tool');
        self::assertStringContainsString('id="map-save"', $html, 'the local map editor can save the map (#1130)');
        self::assertStringContainsString('"careLevel_icu":', $html, 'the editor labels care levels');
        self::assertDoesNotMatchRegularExpression('/<(script|link|img|iframe)[^>]+(src|href)=/i', $html, 'no external resources');
        self::assertSame(2, preg_match_all('/https?:\/\//', strip_tags($html)), 'the update check and the MTS catalog are the only URLs');
    }

    public function testToolsLinkTheLandingPageWhichOffersTheDownload(): void
    {
        $client = self::createClient();
        $client->loginUser($this->user());

        $crawler = $client->request(Request::METHOD_GET, '/tools');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-tools-category="data_preparation"] [data-tool-key="encounter_data_prep"][href="/tools/data-prep"]'), 'the Tools card links the landing page (#1128)');

        $crawler = $client->request(Request::METHOD_GET, '/tools/data-prep');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#navbar-menu li.nav-item.active[data-testid="nav-tools"]');
        $link = $crawler->filter('[data-test="data-prep-download"][href="/tools/data-prep/download"]');
        self::assertCount(1, $link);
        self::assertSame('encounter-data-prep-1.2.0.html', $link->attr('download'));
    }
}
