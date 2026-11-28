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
 * `/login/confirm` or impersonation; wrong codes count towards the login throttling; it fails closed.
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

        $this->submitCode('000000' === $this->currentCode() ? '111111' : '000000');

        $this->followRedirects();
        self::assertRouteSame('app_two_factor_login');
        self::assertSelectorTextContains('[data-test="two-factor-error"]', 'The code is not correct.');
        $this->client->request(Request::METHOD_GET, '/settings/account');
        self::assertResponseRedirects('http://localhost/2fa');
    }

    public function testAccountsWithoutTwoFactorSignInDirectly(): void
    {
        UserFactory::new(['username' => 'plain-user', 'email' => 'plain-user@example.test', 'isVerified' => true])->create();

        $this->loginWithPassword('plain-user');
        $this->followRedirects();

        self::assertSelectorTextContains('#user_name', 'plain-user');
    }

    public function testBackupCodeWorksOnce(): void
    {
        $this->createUserWithTwoFactor('totp-user');

        $this->loginWithPassword('totp-user');
        $this->submitCode('abcde fghjk');
        $this->followRedirects();
        self::assertSelectorTextContains('#user_name', 'totp-user');
        self::assertSame(1, $this->reloadUser('totp-user')->countBackupCodes());

        $this->client->request(Request::METHOD_GET, '/logout');
        $this->loginWithPassword('totp-user');
        $this->submitCode(self::BACKUP_CODE);
        $this->followRedirects();
        self::assertRouteSame('app_two_factor_login');
        self::assertSelectorExists('[data-test="two-factor-error"]');
    }

    public function testWrongCodesCountTowardsTheLoginThrottling(): void
    {
        $this->createUserWithTwoFactor('totp-user');
        $this->loginWithPassword('totp-user');
        $wrongCode = '000000' === $this->currentCode() ? '111111' : '000000';

        // login_throttling allows 5 failures per 15 minutes; the next attempt is refused before the code is checked.
        for ($i = 0; $i < 5; ++$i) {
            $this->submitCode($wrongCode);
        }

        $this->submitCode($this->currentCode());
        $this->followRedirects();
        self::assertSelectorTextContains('[data-test="two-factor-error"]', 'Too many failed login attempts');
        $this->client->request(Request::METHOD_GET, '/settings/account');
        self::assertResponseRedirects('http://localhost/2fa');
    }

    public function testPasswordLoginDoesNotResetTheTwoFactorFailureCount(): void
    {
        $this->createUserWithTwoFactor('totp-user');
        $wrongCode = '000000' === $this->currentCode() ? '111111' : '000000';

        // Three wrong codes, then a fresh password login, which resets login_throttling (IP + username) ...
        $this->loginWithPassword('totp-user');
        for ($i = 0; $i < 3; ++$i) {
            $this->submitCode($wrongCode);
        }

        $this->client->request(Request::METHOD_GET, '/logout');
        $this->loginWithPassword('totp-user');
        $this->followRedirects();
        self::assertRouteSame('app_two_factor_login');

        // ... but not the per-account limit of two_factor_code: two more wrong codes use up its 5 attempts.
        for ($i = 0; $i < 2; ++$i) {
            $this->submitCode($wrongCode);
        }

        $this->submitCode($this->currentCode());
        $this->followRedirects();
        self::assertRouteSame('app_two_factor_login');
        self::assertSelectorTextContains('[data-test="two-factor-error"]', 'Too many failed login attempts');
        $this->client->request(Request::METHOD_GET, '/settings/account');
        self::assertResponseRedirects('http://localhost/2fa');
    }

    public function testTwoFactorFailureCountIsPerAccountAndNotPerIp(): void
    {
        $this->createUserWithTwoFactor('totp-user');
        $wrongCode = '000000' === $this->currentCode() ? '111111' : '000000';

        $this->loginWithPassword('totp-user');
        for ($i = 0; $i < 5; ++$i) {
            $this->submitCode($wrongCode);
        }

        // A new session from another IP signs in with the password but cannot try further codes for this account.
        $this->client->restart();
        $this->client->setServerParameter('REMOTE_ADDR', '192.0.2.44');
        $this->loginWithPassword('totp-user');
        $this->submitCode($this->currentCode());
        $this->followRedirects();
        self::assertSelectorTextContains('[data-test="two-factor-error"]', 'Too many failed login attempts');
    }

    public function testAcceptedCodeResetsTheTwoFactorFailureCount(): void
    {
        $this->createUserWithTwoFactor('totp-user');
        $wrongCode = '000000' === $this->currentCode() ? '111111' : '000000';

        $this->loginWithPassword('totp-user');
        for ($i = 0; $i < 4; ++$i) {
            $this->submitCode($wrongCode);
        }
        $this->submitCode(self::BACKUP_CODE);
        $this->followRedirects();
        self::assertSelectorTextContains('#user_name', 'totp-user');

        // After the accepted code the account has its full allowance again: four wrong codes, then the right one.
        $this->client->request(Request::METHOD_GET, '/logout');
        $this->loginWithPassword('totp-user');
        for ($i = 0; $i < 4; ++$i) {
            $this->submitCode($wrongCode);
        }
        $this->submitCode('ZYXWVUTSRQ');
        $this->followRedirects();
        self::assertSelectorTextContains('#user_name', 'totp-user');
    }

    public function testCodeOfAnUndecryptableSecretIsRejectedButBackupCodesWork(): void
    {
        $user = $this->createUserWithTwoFactor('totp-user');
        // Encrypted with another key, as after a lost or changed APP_TWO_FACTOR_KEY.
        $otherCipher = new TwoFactorSecretCipher(str_repeat('ab', 32));
        $this->entityManager()->getConnection()->executeStatement(
            'UPDATE "user" SET totp_secret_encrypted = :secret WHERE id = :id',
            ['secret' => $otherCipher->encrypt(self::SECRET), 'id' => $user->getId()],
        );

        $this->loginWithPassword('totp-user');
        $this->submitCode($this->currentCode());
        $this->followRedirects();
        self::assertRouteSame('app_two_factor_login');

        $this->submitCode(self::BACKUP_CODE);
        $this->followRedirects();
        self::assertSelectorTextContains('#user_name', 'totp-user');
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

    public function testSecretIsStoredEncrypted(): void
    {
        $user = $this->createUserWithTwoFactor('totp-user');

        $stored = $this->entityManager()->getConnection()->fetchOne(
            'SELECT totp_secret_encrypted FROM "user" WHERE id = :id',
            ['id' => $user->getId()],
        );

        self::assertIsString($stored);
        self::assertStringNotContainsString(self::SECRET, $stored);
        self::assertSame(self::SECRET, self::getContainer()->get(TwoFactorSecretCipher::class)->decrypt($stored));
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

    private function currentCode(): string
    {
        return TOTP::createFromSecret(self::SECRET)->now();
    }

    private function reloadUser(string $username): User
    {
        $this->entityManager()->clear();
        $user = $this->entityManager()->getRepository(User::class)->findOneBy(['username' => $username]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
