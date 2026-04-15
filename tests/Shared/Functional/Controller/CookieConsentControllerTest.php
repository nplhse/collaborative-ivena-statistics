<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class CookieConsentControllerTest extends WebTestCase
{
    public function testHomeSetsSubjectCookieAndShowsBannerForm(): void
    {
        $client = self::createClient();
        $crawler = $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertTrue($client->getResponse()->headers->has('Set-Cookie'));
        $cookies = $client->getResponse()->headers->all('Set-Cookie');
        self::assertTrue(
            array_any($cookies, static fn (string $c): bool => str_contains($c, 'consent_subject_id='))
        );

        self::assertGreaterThan(0, $crawler->filter('form[action="/cookies/banner"]')->count());
        self::assertGreaterThan(0, $crawler->filter('input[name="cookie_consent_banner[_token]"]')->count());
    }

    public function testBannerPostAcceptAllPersistsMonitoring(): void
    {
        $client = self::createClient();
        $crawler = $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="cookie_consent_banner[_token]"]')->first()->attr('value');
        self::assertIsString($token);
        self::assertNotSame('', $token);

        $client->request(Request::METHOD_POST, '/cookies/banner', [
            'cookie_consent_banner' => [
                '_token' => $token,
                'target' => '/',
                'all' => '',
            ],
        ]);

        self::assertResponseRedirects('/');
        $crawlerAfter = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawlerAfter->filter('form[action="/cookies/banner"]'));
    }

    public function testPreferencesPageIsReachable(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/cookies/preferences');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Cookie preferences', $client->getResponse()->getContent());
    }

    public function testSavePreferencesWithValidCsrfUpdatesConsent(): void
    {
        $client = self::createClient();
        $crawler = $client->request(Request::METHOD_GET, '/cookies/preferences');

        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
        self::assertIsString($token);

        $client->request(Request::METHOD_POST, '/cookies/preferences', [
            '_token' => $token,
            'monitoring' => '1',
        ]);

        self::assertResponseRedirects('/cookies/preferences');
    }
}
