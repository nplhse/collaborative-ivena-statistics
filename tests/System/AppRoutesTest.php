<?php

declare(strict_types=1);

namespace App\Tests\System;

use App\Tests\Support\System\SystemWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AppRoutesTest extends SystemWebTestCase
{
    #[DataProvider('getPublicUrls')]
    public function testPublicUrlsAreReachable(string $url): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
    }

    #[DataProvider('getSecureUrls')]
    public function testSecureUrlsAreRestricted(string $url): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseRedirects(
            '/login',
            Response::HTTP_FOUND
        );
    }

    public function testLogoutUrlRedirects(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/logout');

        self::assertResponseRedirects(
            '/',
            Response::HTTP_FOUND
        );
    }

    public function testFeaturesRouteIsNotAvailable(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/features');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public static function getPublicUrls(): \Generator
    {
        yield 'app_default' => ['/'];
        yield 'app_login' => ['/login'];
        yield 'app_register' => ['/register'];
        yield 'app_register_check_email' => ['/register/check-email'];
        yield 'app_forgot_password_request' => ['/reset-password'];
        yield 'app_check_email' => ['/reset-password/check-email'];
        yield 'app_cookie_preferences' => ['/cookies/preferences'];
        yield 'app_sitemap' => ['/sitemap'];
        yield 'app_blog_index' => ['/blog'];
        yield 'app_blog_rss' => ['/blog/rss.xml'];
    }

    public static function getSecureUrls(): \Generator
    {
        yield 'app_admin_dashboard' => ['/admin/'];
        yield 'app_confirm_password' => ['/login/confirm'];
        yield 'app_settings_index' => ['/settings'];
        yield 'app_settings_email' => ['/settings/email'];
        yield 'app_settings_password' => ['/settings/password'];
    }
}
