<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CookieConsentControllerTest extends WebTestCase
{
    public function testCurrentReturnsJsonAndSetsSubjectCookie(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/cookies/consent/current');

        self::assertResponseIsSuccessful();
        self::assertResponseFormatSame('json');
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('decided', $data);
        self::assertFalse($data['decided']);
        self::assertArrayHasKey('preferences', $data);
        self::assertFalse($data['preferences']['monitoring']);
        self::assertTrue($data['preferences']['essential']);

        self::assertTrue($client->getResponse()->headers->has('Set-Cookie'));
        $cookies = $client->getResponse()->headers->all('Set-Cookie');
        self::assertNotEmpty($cookies);
        self::assertTrue(
            array_any($cookies, static fn (string $c): bool => str_contains($c, 'consent_subject_id='))
        );
    }

    public function testUpdateRejectsInvalidCsrf(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/cookies/consent/current');

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/cookies/consent/update', [
            '_token' => 'invalid',
            'monitoring' => '1',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testUpdateWithValidCsrfSetsMonitoringAndDecided(): void
    {
        $client = self::createClient();

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/cookies/preferences');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
        self::assertIsString($token);
        self::assertNotSame('', $token);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/cookies/consent/current');

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/cookies/consent/update', [
            '_token' => $token,
            'monitoring' => '1',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertTrue($data['decided']);
        self::assertTrue($data['preferences']['monitoring']);
    }

    public function testPreferencesPageIsReachable(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/cookies/preferences');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Cookie preferences', $client->getResponse()->getContent());
    }
}
