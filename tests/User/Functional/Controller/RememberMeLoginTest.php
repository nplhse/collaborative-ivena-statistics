<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use App\Tests\Support\Browser\CookieConsentTestHelper;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class RememberMeLoginTest extends WebTestCase
{
    use CookieConsentTestHelper;
    use Factories;
    use HasBrowser;

    private const int REMEMBER_ME_LIFETIME_SECONDS = 604800;

    public function testLoginWithRememberMeCheckboxSetsRememberMeCookie(): void
    {
        UserFactory::new([
            'email' => 'remember-cookie@example.test',
            'isVerified' => true,
            'username' => 'remember-cookie-user',
        ])->create();

        $client = $this->createLoginClient();
        $this->loginWithRememberMe($client, 'remember-cookie-user');

        $rememberMe = $client->getCookieJar()->get('REMEMBERME');
        self::assertNotNull($rememberMe);
        self::assertFalse($rememberMe->isExpired());
    }

    public function testLoginWithoutRememberMeCheckboxDoesNotSetRememberMeCookie(): void
    {
        UserFactory::new([
            'email' => 'no-remember@example.test',
            'isVerified' => true,
            'username' => 'no-remember-user',
        ])->create();

        $client = $this->createLoginClient();
        $this->loginWithoutRememberMe($client, 'no-remember-user');

        self::assertNull($client->getCookieJar()->get('REMEMBERME'));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#user_name');
    }

    public function testRememberMeCookieExpiresAfterConfiguredLifetime(): void
    {
        UserFactory::new([
            'email' => 'remember-expiry@example.test',
            'isVerified' => true,
            'username' => 'remember-expiry-user',
        ])->create();

        $client = $this->createLoginClient();
        $beforeLogin = time();
        $this->loginWithRememberMe($client, 'remember-expiry-user');
        $afterLogin = time();

        $rememberMe = $client->getCookieJar()->get('REMEMBERME');
        self::assertNotNull($rememberMe);

        $expiresAt = $rememberMe->getExpiresTime();
        self::assertNotNull($expiresAt);
        self::assertGreaterThanOrEqual($beforeLogin + self::REMEMBER_ME_LIFETIME_SECONDS, $expiresAt);
        self::assertLessThanOrEqual($afterLogin + self::REMEMBER_ME_LIFETIME_SECONDS, $expiresAt);
    }

    public function testRememberMeCookieRestoresAuthenticationAfterSessionCleared(): void
    {
        UserFactory::new([
            'email' => 'remember-restore@example.test',
            'isVerified' => true,
            'username' => 'remember-restore-user',
        ])->create();

        $client = $this->createLoginClient();
        $this->loginWithRememberMe($client, 'remember-restore-user');
        $cookies = $this->extractRememberMeSessionCookies($client);

        $this->useRememberMeSessionOnly($client, $cookies['rememberMe'], $cookies['consentSubject']);

        $client->request(Request::METHOD_GET, '/settings');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Account Settings');
    }

    private function createLoginClient(): KernelBrowser
    {
        $client = $this->browser()->client();
        $client->getContainer()->get('cache.rate_limiter')->clear();

        return $client;
    }

    private function loginWithoutRememberMe(KernelBrowser $client, string $username, string $password = 'password'): void
    {
        $this->acceptEssentialCookiesOnly($client);

        $crawler = $client->request(Request::METHOD_GET, '/login');
        $form = $crawler->selectButton('Sign in')->form([
            'login[username]' => $username,
            'login[password]' => $password,
        ]);
        $client->submit($form);

        if ($client->getResponse()->isRedirect()) {
            $client->followRedirect();
        }
    }
}
