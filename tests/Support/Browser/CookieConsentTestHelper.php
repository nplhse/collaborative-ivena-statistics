<?php

declare(strict_types=1);

namespace App\Tests\Support\Browser;

use App\Shared\Domain\Entity\CookieConsent;
use App\Shared\Infrastructure\Consent\CookieConsentService;
use App\Shared\Infrastructure\Repository\CookieConsentRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser as SymfonyKernelBrowser;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Browser\KernelBrowser;

trait CookieConsentTestHelper
{
    /**
     * Stores the decision "essential cookies only" and sets the subject cookie, as the banner does, without rendering
     * a page and posting the banner (two requests per sign-in, #1348). The banner itself is tested in
     * tests/Shared/Functional/Consent.
     */
    protected function acceptEssentialCookies(KernelBrowser $browser): KernelBrowser
    {
        $this->storeEssentialCookieDecision($browser->client());

        return $browser;
    }

    protected function acceptAnalyticsCookies(KernelBrowser $browser): KernelBrowser
    {
        $hasBanner = false;

        $browser
            ->visit('/')
            ->assertSuccessful()
            ->use(static function (Crawler $crawler) use (&$hasBanner): void {
                $hasBanner = $crawler->filter('form[action="/cookies/banner"] button[name="cookie_consent_banner[all]"]')->count() > 0;
            });

        if ($hasBanner) {
            $browser
                ->click('Allow All Cookies')
                ->assertSuccessful();
        }

        return $browser;
    }

    protected function loginWithConsent(KernelBrowser $browser, string $username, string $password = 'password'): KernelBrowser
    {
        return $this->acceptEssentialCookies($browser)
            ->visit('/login')
            ->assertSeeIn('title', 'Login')
            ->fillField('Username or email', $username)
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
     * @return array{rememberMe: BrowserKitCookie, consentSubject: ?BrowserKitCookie}
     */
    protected function extractRememberMeSessionCookies(SymfonyKernelBrowser $client): array
    {
        $rememberMe = $client->getCookieJar()->get('REMEMBERME');
        if (!$rememberMe instanceof BrowserKitCookie) {
            self::fail('REMEMBERME cookie was not set after login with remember me.');
        }

        return [
            'rememberMe' => $rememberMe,
            'consentSubject' => $client->getCookieJar()->get('consent_subject_id'),
        ];
    }

    protected function useRememberMeSessionOnly(
        SymfonyKernelBrowser $client,
        BrowserKitCookie $rememberMeCookie,
        ?BrowserKitCookie $consentSubjectCookie = null,
    ): SymfonyKernelBrowser {
        $client->getCookieJar()->clear();
        $client->getCookieJar()->set($rememberMeCookie);

        if ($consentSubjectCookie instanceof BrowserKitCookie) {
            $client->getCookieJar()->set($consentSubjectCookie);
        }

        return $client;
    }

    protected function acceptEssentialCookiesOnly(SymfonyKernelBrowser $client): void
    {
        $this->storeEssentialCookieDecision($client);
    }

    private function storeEssentialCookieDecision(SymfonyKernelBrowser $client): void
    {
        $container = static::getContainer();
        $repository = $container->get(CookieConsentRepository::class);
        $name = $container->get(CookieConsentService::class)->subjectCookieName();

        // A visitor who already got a subject cookie decides for that subject, as on the banner.
        $subjectId = $client->getCookieJar()->get($name)?->getValue() ?? bin2hex(random_bytes(16));
        $consent = $repository->findOneBySubjectId($subjectId) ?? new CookieConsent($subjectId);
        $repository->save($consent->setConsents(false));

        $client->getCookieJar()->set(new BrowserKitCookie($name, $subjectId));
    }
}
