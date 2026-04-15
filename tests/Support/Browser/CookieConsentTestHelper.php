<?php

declare(strict_types=1);

namespace App\Tests\Support\Browser;

use Symfony\Component\DomCrawler\Crawler;
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
}
