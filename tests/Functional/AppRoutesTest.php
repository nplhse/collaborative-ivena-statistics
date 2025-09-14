<?php

namespace App\Tests\Functional;

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
}
