<?php

declare(strict_types=1);

namespace App\Tests\Support\Browser;

use Symfony\Bundle\FrameworkBundle\KernelBrowser as SymfonyKernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Browser\KernelBrowser;

trait CookieConsentTestHelper
{
    protected function acceptEssentialCookies(KernelBrowser $browser): KernelBrowser
    {
        $hasBanner = false;

        $browser
            ->visit('/')
            ->assertSuccessful()
            ->use(static function (Crawler $crawler) use (&$hasBanner): void {
                $hasBanner = $crawler->filter('form[action="/cookies/banner"] button[name="cookie_consent_banner[essential]"]')->count() > 0;
            });

        if ($hasBanner) {
            $browser
                ->click('Essential Cookies Only')
                ->assertSuccessful();
        }

        return $browser;
    }

    protected function loginWithConsent(KernelBrowser $browser, string $username, string $password = 'password'): KernelBrowser
    {
        return $this->acceptEssentialCookies($browser)
            ->visit('/login')
            ->assertSeeIn('title', 'Login')
            ->fillField('Username', $username)
            ->fillField('Password', $password)
            ->click('Sign in')
            ->assertSuccessful();
    }

    protected function loginWithRememberMe(SymfonyKernelBrowser $client, string $username, string $password = 'password'): void
    {
        $this->acceptEssentialCookiesOnly($client);

        $crawler = $client->request(Request::METHOD_GET, '/login');
        $form = $crawler->selectButton('Sign in')->form([
            'login[username]' => $username,
            'login[password]' => $password,
            'login[_remember_me]' => true,
        ]);
        $client->submit($form);

        if ($client->getResponse()->isRedirect()) {
            $client->followRedirect();
        }
    }

    /**
     * @return array{rememberMe: \Symfony\Component\BrowserKit\Cookie, consentSubject: ?\Symfony\Component\BrowserKit\Cookie}
     */
    protected function extractRememberMeSessionCookies(SymfonyKernelBrowser $client): array
    {
        $rememberMe = $client->getCookieJar()->get('REMEMBERME');
        if (!$rememberMe instanceof \Symfony\Component\BrowserKit\Cookie) {
            self::fail('REMEMBERME cookie was not set after login with remember me.');
        }

        return [
            'rememberMe' => $rememberMe,
            'consentSubject' => $client->getCookieJar()->get('consent_subject_id'),
        ];
    }

    protected function useRememberMeSessionOnly(
        SymfonyKernelBrowser $client,
        \Symfony\Component\BrowserKit\Cookie $rememberMeCookie,
        ?\Symfony\Component\BrowserKit\Cookie $consentSubjectCookie = null,
    ): SymfonyKernelBrowser {
        $client->getCookieJar()->clear();
        $client->getCookieJar()->set($rememberMeCookie);

        if ($consentSubjectCookie instanceof \Symfony\Component\BrowserKit\Cookie) {
            $client->getCookieJar()->set($consentSubjectCookie);
        }

        return $client;
    }

    protected function acceptEssentialCookiesOnly(SymfonyKernelBrowser $client): void
    {
        $client->request(Request::METHOD_GET, '/');

        $crawler = $client->getCrawler();
        $hasBannerButton = $crawler->filter('form[action="/cookies/banner"] button[name="cookie_consent_banner[essential]"]')->count() > 0;

        if ($hasBannerButton) {
            $form = $crawler->selectButton('Essential Cookies Only')->form();
            $client->submit($form);
        }
    }
}
