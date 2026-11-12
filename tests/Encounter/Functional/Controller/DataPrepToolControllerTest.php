<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\Controller;

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
            'version' => '1.0.2',
            'minVersion' => '1.0.0',
            'releasedAt' => '2026-11-12',
            'downloadUrl' => 'http://localhost/encounter/data-prep/download',
        ], json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testDownloadNeedsTheBetaRole(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/encounter/data-prep/download');
        self::assertResponseRedirects();

        $client->loginUser($this->user(['ROLE_USER', 'ROLE_PARTICIPANT']));
        $client->request(Request::METHOD_GET, '/encounter/data-prep/download');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testBetaUserDownloadsTheSelfContainedTool(): void
    {
        $client = self::createClient();
        $client->loginUser($this->user());
        $client->request(Request::METHOD_GET, '/encounter/data-prep/download');

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertSame('attachment; filename=encounter-data-prep-1.0.2.html', $response->headers->get('Content-Disposition'));
        $html = (string) $response->getContent();

        self::assertStringContainsString(
            "content=\"default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; connect-src http://localhost/data-prep/latest.json;",
            str_replace('&#039;', "'", $html),
        );
        self::assertStringContainsString('globalThis.EncounterDataPrep', $html, 'core script is inlined');
        self::assertStringContainsString('"id":"orbis-mts"', $html, 'public profile is bundled');
        self::assertStringContainsString('"name":"visit_fingerprint"', $html, 'field contract is bundled');
        self::assertDoesNotMatchRegularExpression('/<(script|link|img|iframe)[^>]+(src|href)=/i', $html, 'no external resources');
        self::assertSame(1, preg_match_all('/https?:\/\//', strip_tags($html)), 'the update check is the only URL');
    }

    public function testOverviewOffersTheDownload(): void
    {
        $client = self::createClient();
        $client->loginUser($this->user());
        $crawler = $client->request(Request::METHOD_GET, '/encounter');

        self::assertResponseIsSuccessful();
        $link = $crawler->filter('[data-test="encounter-data-prep"] a[href="/encounter/data-prep/download"]');
        self::assertCount(1, $link);
        self::assertSame('encounter-data-prep-1.0.2.html', $link->attr('download'));
    }
}
