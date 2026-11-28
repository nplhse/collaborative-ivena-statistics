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

/**
 * Re-entering the password: a session restored from the remember-me cookie must confirm the password before it
 * changes the password or the address; a fully signed-in session never sees the page. That a remembered session
 * may open the other settings pages: RememberMeLoginTest.
 */
#[ResetDatabase]
final class ConfirmPasswordControllerTest extends WebTestCase
{
    use CookieConsentTestHelper;
    use Factories;
    use HasBrowser;

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

        // The client follows the redirect back to the page that asked for the confirmation.
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
        $client = $this->browser()->client();
        $client->followRedirects(false);
        $client->loginUser(UserFactory::createOne(['username' => 'alice', 'isVerified' => true]));

        $client->request(Request::METHOD_GET, '/login/confirm');

        self::assertResponseRedirects('/');
    }

    private function createRememberMeOnlyClient(string $username): KernelBrowser
    {
        $client = $this->browser()->client();
        $this->loginWithRememberMe($client, $username);
        $cookies = $this->extractRememberMeSessionCookies($client);
        $this->useRememberMeSessionOnly($client, $cookies['rememberMe'], $cookies['consentSubject']);

        return $client;
    }
}
