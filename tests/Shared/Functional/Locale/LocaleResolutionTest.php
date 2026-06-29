<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Locale;

use App\Shared\Infrastructure\Locale\LocaleCookieManager;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class LocaleResolutionTest extends WebTestCase
{
    use Factories;

    public function testGuestWithGermanBrowserGetsGermanLocale(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/', server: [
            'HTTP_Accept-Language' => 'de-DE,de;q=0.9',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang="de"]');
    }

    public function testGuestWithNonGermanBrowserGetsEnglishLocale(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/', server: [
            'HTTP_Accept-Language' => 'fr-FR,fr;q=0.9',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang="en"]');
    }

    public function testLoggedInUserWithExplicitLocaleIgnoresCookie(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'de-user', 'locale' => 'de']);

        $client->loginUser($user);
        $client->getCookieJar()->set($this->createLocaleCookie('en'));
        $client->request(Request::METHOD_GET, '/settings');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang="de"]');
    }

    public function testLoggedInUserWithoutLocaleFollowsCookie(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'cookie-user', 'locale' => null]);

        $client->loginUser($user);
        $client->getCookieJar()->set($this->createLocaleCookie('de'));
        $client->request(Request::METHOD_GET, '/settings');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang="de"]');
    }

    public function testLocaleSwitchSetsCookiePersistsUserLocaleAndRedirectsToSamePath(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'switch-user', 'locale' => null]);

        $client->loginUser($user);
        $client->request(
            Request::METHOD_GET,
            '/locale/switch/de?_target_path=/settings',
        );

        self::assertResponseRedirects('/settings');

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang="de"]');

        $cookie = $client->getCookieJar()->get(LocaleCookieManager::COOKIE_NAME);
        self::assertNotNull($cookie);
        self::assertSame('de', $cookie->getValue());

        UserFactory::assert()->exists([
            'username' => 'switch-user',
            'locale' => 'de',
        ]);
    }

    public function testGuestLocaleSwitchShowsGermanNavigationLabels(): void
    {
        $client = self::createClient();
        $client->request(
            Request::METHOD_GET,
            '/locale/switch/de?_target_path=/login',
        );

        self::assertResponseRedirects('/login');

        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang="de"]');
        self::assertSelectorTextContains('body', 'Registrieren');
    }

    public function testStatisticsExplorerSlugRouteWorksWithGermanLocale(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'stats-user', 'roles' => ['ROLE_USER'], 'locale' => 'de']);

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/statistics/analysis/explorer');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang="de"]');
    }

    public function testGuestLocaleSwitchWithoutTargetPathRedirectsToHome(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/locale/switch/de');

        self::assertResponseRedirects('/');

        $cookie = $client->getCookieJar()->get(LocaleCookieManager::COOKIE_NAME);
        self::assertNotNull($cookie);
        self::assertSame('de', $cookie->getValue());
    }

    public function testLoggedInUserLocaleSwitchWithoutTargetPathRedirectsToStatisticsDashboard(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'redirect-user', 'roles' => ['ROLE_USER'], 'locale' => 'de']);

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/locale/switch/en');

        self::assertResponseRedirects('/statistics/');
    }

    public function testLocaleSwitchUsesRefererWhenTargetPathIsMissing(): void
    {
        $client = self::createClient();
        $client->request(
            Request::METHOD_GET,
            '/locale/switch/de',
            server: ['HTTP_REFERER' => 'http://localhost/login'],
        );

        self::assertResponseRedirects('/login');
    }

    private function createLocaleCookie(string $locale): \Symfony\Component\BrowserKit\Cookie
    {
        return new \Symfony\Component\BrowserKit\Cookie(
            LocaleCookieManager::COOKIE_NAME,
            $locale,
            null,
            '/',
        );
    }
}
