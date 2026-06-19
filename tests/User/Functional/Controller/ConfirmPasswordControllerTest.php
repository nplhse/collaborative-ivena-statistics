<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use App\Tests\Support\Browser\CookieConsentTestHelper;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ConfirmPasswordControllerTest extends WebTestCase
{
    use CookieConsentTestHelper;
    use Factories;
    use HasBrowser;

    public function testRememberMeUserCanAccessSettingsOverviewWithoutConfirm(): void
    {
        UserFactory::new([
            'email' => 'remember@example.test',
            'isVerified' => true,
            'username' => 'remember-user',
        ])->create();

        $client = $this->createRememberMeOnlyClient('remember-user');

        $client->request(Request::METHOD_GET, '/settings');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Account Settings');
        self::assertStringNotContainsString('Confirm your password', (string) $client->getResponse()->getContent());
    }

    public function testRememberMeUserMustConfirmPasswordBeforeChangingPassword(): void
    {
        UserFactory::new([
            'email' => 'remember-pwd@example.test',
            'isVerified' => true,
            'username' => 'remember-pwd-user',
        ])->create();

        $client = $this->createRememberMeOnlyClient('remember-pwd-user');

        $client->request(Request::METHOD_GET, '/settings/password');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Confirm your password');
        self::assertStringNotContainsString('Change password', (string) $client->getResponse()->getContent());
    }

    public function testRememberMeUserIsRedirectedToConfirmPasswordWhenAccessingChangeEmail(): void
    {
        UserFactory::new([
            'email' => 'remember-email@example.test',
            'isVerified' => true,
            'username' => 'remember-email-user',
        ])->create();

        $client = $this->createRememberMeOnlyClient('remember-email-user');

        $client->request(Request::METHOD_GET, '/settings/email');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Confirm your password');
    }

    public function testRememberMeUserCanConfirmPasswordAndAccessChangeEmail(): void
    {
        UserFactory::new([
            'email' => 'remember-ok@example.test',
            'isVerified' => true,
            'username' => 'remember-ok-user',
        ])->create();

        $client = $this->createRememberMeOnlyClient('remember-ok-user');

        $client->request(Request::METHOD_GET, '/settings/email');
        self::assertSelectorTextContains('h2', 'Confirm your password');

        $crawler = $client->getCrawler();
        $csrfToken = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        self::assertIsString($csrfToken);

        $client->request(Request::METHOD_POST, '/login/confirm', [
            'confirm_password' => [
                'password' => 'password',
            ],
            '_csrf_token' => $csrfToken,
        ]);

        if ($client->getResponse()->isRedirect()) {
            $client->followRedirect();
        }

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Change email');
    }

    public function testWrongPasswordShowsErrorOnConfirmPage(): void
    {
        UserFactory::new([
            'email' => 'remember-bad@example.test',
            'isVerified' => true,
            'username' => 'remember-bad-user',
        ])->create();

        $client = $this->createRememberMeOnlyClient('remember-bad-user');

        $client->request(Request::METHOD_GET, '/login/confirm');

        $csrfToken = $client->getCrawler()->filter('input[name="_csrf_token"]')->attr('value');
        self::assertIsString($csrfToken);

        $client->request(Request::METHOD_POST, '/login/confirm', [
            'confirm_password' => [
                'password' => 'wrong-password',
            ],
            '_csrf_token' => $csrfToken,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Confirm your password');
        self::assertSelectorExists('.alert.alert-danger');
    }

    public function testFullyAuthenticatedUserIsRedirectedAwayFromConfirmPage(): void
    {
        UserFactory::new([
            'email' => 'full-auth@example.test',
            'isVerified' => true,
            'username' => 'full-auth-user',
        ])->create();

        $client = $this->browser()->client();
        $this->acceptEssentialCookiesOnly($client);

        $crawler = $client->request(Request::METHOD_GET, '/login');
        $form = $crawler->selectButton('Sign in')->form([
            'login[username]' => 'full-auth-user',
            'login[password]' => 'password',
        ]);
        $client->submit($form);

        if ($client->getResponse()->isRedirect()) {
            $client->followRedirect();
        }

        $client->request(Request::METHOD_GET, '/login/confirm');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Confirm your password', (string) $client->getResponse()->getContent());
    }

    private function createRememberMeOnlyClient(string $username): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = $this->browser()->client();
        $this->loginWithRememberMe($client, $username);
        $cookies = $this->extractRememberMeSessionCookies($client);
        $this->useRememberMeSessionOnly($client, $cookies['rememberMe'], $cookies['consentSubject']);

        return $client;
    }
}
