<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use App\Tests\Support\Browser\CookieConsentTestHelper;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorSecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Second factor at sign-in (#1357, ADR 040): asked after the password and the login link, not for remember-me,
 * `/login/confirm` or impersonation; wrong codes are throttled per account.
 *
 * Checked lower down: the per-account limit, its reset by an accepted code and the minutes in the message in
 * `TwoFactorFailureThrottlingListenerTest`; an undecryptable secret in `TwoFactorSecretLoaderTest` and
 * `UserTwoFactorTest`; case and separators of backup codes in `UserTwoFactorTest`; the encryption of the secret in
 * `TwoFactorSecretCipherTest` and, on the real setup path, `TwoFactorSettingsTest`. A password sign-in without two-factor
 * authentication is in `SecurityControllerTest`.
 */
#[ResetDatabase]
final class TwoFactorLoginTest extends WebTestCase
{
    use CookieConsentTestHelper;
    use Factories;

    private const string SECRET = 'JBSWY3DPEHPK3PXP';

    private const string BACKUP_CODE = 'ABCDE-FGHJK';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->client->getContainer()->get('cache.rate_limiter')->clear();
        $this->client->getContainer()->get('cache.app')->clear();
    }

    public function testPasswordLoginAsksForTheCodeBeforeSigningIn(): void
    {
        $this->createUserWithTwoFactor('totp-user');

        $this->loginWithPassword('totp-user');

        self::assertResponseRedirects();
        $this->followRedirects();
        self::assertRouteSame('app_two_factor_login');
        self::assertSelectorTextContains('h2', 'Two-factor authentication');

        // Every other page leads back to the code form until the code is entered.
        $this->client->request(Request::METHOD_GET, '/settings/account');
        self::assertResponseRedirects('http://localhost/2fa');

        $this->submitCode($this->currentCode());
        self::assertResponseRedirects();
        $this->followRedirects();
        self::assertSelectorTextContains('#user_name', 'totp-user');
    }

    public function testWrongCodeKeepsTheUserOnTheCodeForm(): void
    {
        $this->createUserWithTwoFactor('totp-user');
        $this->loginWithPassword('totp-user');

        $this->submitCode($this->wrongCode());

        $this->followRedirects();
        self::assertRouteSame('app_two_factor_login');
        self::assertSelectorTextContains('[data-test="two-factor-error"]', 'The code is not correct.');
        $this->client->request(Request::METHOD_GET, '/settings/account');
        self::assertResponseRedirects('http://localhost/2fa');
    }

    public function testBackupCodeWorksOnce(): void
    {
        $this->createUserWithTwoFactor('totp-user');
        $this->loginWithPassword('totp-user');
        $this->submitCode(self::BACKUP_CODE);
        $this->followRedirects();
        self::assertSelectorTextContains('#user_name', 'totp-user');
        $this->client->request(Request::METHOD_GET, '/logout');
        $this->loginWithPassword('totp-user');

        $this->submitCode(self::BACKUP_CODE);

        // The code was invalidated and saved at the first sign-in, so the fresh login loads the account without it.
        $this->followRedirects();
        self::assertRouteSame('app_two_factor_login');
        self::assertSelectorExists('[data-test="two-factor-error"]');
    }

    public function testPasswordLoginDoesNotResetTheTwoFactorFailureCount(): void
    {
        $this->createUserWithTwoFactor('totp-user');

        // Three wrong codes, then a fresh password login, which resets login_throttling (IP + username) ...
        $this->loginWithPassword('totp-user');
        for ($i = 0; $i < 3; ++$i) {
            $this->submitCode($this->wrongCode());
        }

        $this->client->request(Request::METHOD_GET, '/logout');
        $this->loginWithPassword('totp-user');
        $this->followRedirects();
        self::assertRouteSame('app_two_factor_login');

        // ... but not the per-account limit of two_factor_code: two more wrong codes use up its 5 attempts.
        for ($i = 0; $i < 2; ++$i) {
            $this->submitCode($this->wrongCode());
        }

        $this->submitCode($this->currentCode());
        $this->followRedirects();
        self::assertRouteSame('app_two_factor_login');
        self::assertSelectorTextContains('[data-test="two-factor-error"]', 'Too many failed login attempts');
        $this->client->request(Request::METHOD_GET, '/settings/account');
        self::assertResponseRedirects('http://localhost/2fa');
    }

    public function testLoginLinkAsksForTheCodeAsWell(): void
    {
        $this->createUserWithTwoFactor('totp-user', ['loginLinkEnabled' => true]);
        $this->acceptEssentialCookiesOnly($this->client);
        $crawler = $this->client->request(Request::METHOD_GET, '/login/link');
        $this->client->submit($crawler->selectButton('Send me a sign-in link')->form(['identifier' => 'totp-user']));
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame(1, preg_match('#href="(https?://[^"]+/login/link/check\?[^"]+)"#', (string) $email->getHtmlBody(), $match));

        $crawler = $this->client->request(Request::METHOD_GET, html_entity_decode($match[1]));
        $this->client->submit($crawler->selectButton('Sign in')->form());
        $this->followRedirects();
        self::assertRouteSame('app_two_factor_login');

        $this->submitCode($this->currentCode());
        $this->followRedirects();
        self::assertSelectorTextContains('#user_name', 'totp-user');
    }

    public function testRememberMeCookieIsSetAfterTheCodeAndSkipsItLater(): void
    {
        $this->createUserWithTwoFactor('totp-user');

        $this->loginWithRememberMe($this->client, 'totp-user');
        $this->followRedirects();
        self::assertRouteSame('app_two_factor_login');
        self::assertNull($this->client->getCookieJar()->get('REMEMBERME'));

        $this->submitCode($this->currentCode());
        $cookies = $this->extractRememberMeSessionCookies($this->client);

        $this->useRememberMeSessionOnly($this->client, $cookies['rememberMe'], $cookies['consentSubject']);
        $this->client->request(Request::METHOD_GET, '/settings/account');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Account Settings');
    }

    public function testPasswordConfirmationDoesNotAskForTheCodeAgain(): void
    {
        $this->createUserWithTwoFactor('totp-user');
        $this->loginWithRememberMe($this->client, 'totp-user');
        $this->submitCode($this->currentCode());
        $cookies = $this->extractRememberMeSessionCookies($this->client);
        $this->useRememberMeSessionOnly($this->client, $cookies['rememberMe'], $cookies['consentSubject']);

        $this->client->request(Request::METHOD_GET, '/settings/email');
        $this->followRedirects();
        self::assertSelectorTextContains('h2', 'Confirm your password');
        $csrfToken = $this->client->getCrawler()->filter('input[name="_csrf_token"]')->attr('value');
        $this->client->request(Request::METHOD_POST, '/login/confirm', [
            'confirm_password' => ['password' => 'password'],
            '_csrf_token' => $csrfToken,
        ]);
        $this->followRedirects();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Change email');
    }

    public function testImpersonationDoesNotAskForTheCode(): void
    {
        $target = $this->createUserWithTwoFactor('totp-user');
        $admin = UserFactory::new()->asAdmin()->create(['username' => 'impersonator']);

        $this->client->loginUser($admin);
        $this->client->request(Request::METHOD_GET, '/?_switch_user='.$target->getUserIdentifier());
        $this->followRedirects();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'End impersonation');
    }

    public function testCodeFormCanBeLeftBySigningOut(): void
    {
        $this->createUserWithTwoFactor('totp-user');
        $this->loginWithPassword('totp-user');

        $this->client->request(Request::METHOD_GET, '/logout');
        $this->client->request(Request::METHOD_GET, '/settings/account');

        self::assertResponseRedirects('/login');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createUserWithTwoFactor(string $username, array $attributes = []): User
    {
        $user = UserFactory::new(array_merge([
            'username' => $username,
            'email' => $username.'@example.test',
            'isVerified' => true,
        ], $attributes))->create();

        $user->enableTotpAuthentication(
            self::getContainer()->get(TwoFactorSecretCipher::class)->encrypt(self::SECRET),
            self::SECRET,
            // Lowest bcrypt cost: the default cost makes every hash and every check take about 0.25 s.
            [password_hash(User::normalizeBackupCode(self::BACKUP_CODE), \PASSWORD_BCRYPT, ['cost' => 4]), password_hash('ZYXWVUTSRQ', \PASSWORD_BCRYPT, ['cost' => 4])],
            new \DateTimeImmutable('2026-11-22 12:00:00'),
        );
        $this->entityManager()->flush();

        return $user;
    }

    private function loginWithPassword(string $username): void
    {
        $this->acceptEssentialCookiesOnly($this->client);
        $crawler = $this->client->request(Request::METHOD_GET, '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'login[username]' => $username,
            'login[password]' => 'password',
        ]));
    }

    private function submitCode(string $code): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/2fa');
        $this->client->submit($crawler->selectButton('Verify')->form(['_auth_code' => $code]));
    }

    private function followRedirects(): void
    {
        while ($this->client->getResponse()->isRedirect()) {
            $this->client->followRedirect();
        }
    }

    /**
     * TOTP::now() reads the wall clock, as the code check does; the leeway of 10 seconds (scheb_2fa.yaml) keeps a code
     * taken at the end of a period valid. A code is accepted only once within 90 seconds (code_reuse_cache), so a test
     * that signs in twice uses a backup code for one of the sign-ins.
     */
    private function currentCode(): string
    {
        return TOTP::createFromSecret(self::SECRET)->now();
    }

    private function wrongCode(): string
    {
        return '000000' === $this->currentCode() ? '111111' : '000000';
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
