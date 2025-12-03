<?php

namespace App\Tests\System;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class AppRoutesTest extends WebTestCase
{
    #[DataProvider('getPublicUrls')]
    public function testPublicUrlsAreReachable(string $url): void
    {
        $client = static::createClient();
        $client->request('GET', $url);

        self::assertResponseIsSuccessful();
    }

    #[DataProvider('getSecureUrls')]
    public function testSecureUrlsAreRestricted(string $url): void
    {
        $client = static::createClient();
        $client->request('GET', $url);

        self::assertResponseRedirects(
            '/login',
            Response::HTTP_FOUND
        );
    }

    public function testLogoutUrlRedirects(): void
    {
        $client = static::createClient();
        $client->request('GET', '/logout');

        self::assertResponseRedirects(
            '/',
            Response::HTTP_FOUND
        );
    }

    public static function getPublicUrls(): ?\Generator
    {
        yield 'app_default' => ['/'];
        yield 'app_login' => ['/login'];
    }

    public function getSecureUrls(): ?\Generator
    {
        yield 'app_admin_dashboard' => ['/admin/'];
    }
}
