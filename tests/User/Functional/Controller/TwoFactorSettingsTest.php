<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use App\Tests\Support\Browser\CookieConsentTestHelper;
use App\Tests\Support\RateLimit\DeniesRateLimiter;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorAvailability;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorSecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Two-factor authentication in the settings (#1357): setup with QR code and first code, backup codes shown once,
 * renewing them, switching off with a current code and not at all for administrators under the obligation.
 *
 * Checked lower down: format and uniqueness of the backup codes in `BackupCodeGeneratorTest`; the settings navigation
 * entry in `SettingsNavigationExtensionTest`.
 */
#[ResetDatabase]
final class TwoFactorSettingsTest extends WebTestCase
{
    use DeniesRateLimiter;
    use CookieConsentTestHelper;
    use Factories;

    private const string SECRET = 'JBSWY3DPEHPK3PXP';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->client->getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testTheSecurityOverviewShowsTheStatusOfEverySubItem(): void
    {
        $this->signIn($this->createUser());

        $this->client->request(Request::METHOD_GET, '/settings/security');

        // #1508. The password row offers the change; every account has one, so it shows no status badge.
        self::assertSelectorExists('a[data-test="security-password"][href="/settings/password"]');
        self::assertSelectorNotExists('[data-test="security-password"] .badge');
        self::assertSelectorTextContains('[data-test="security-login-link-status"]', 'On');
        self::assertSelectorTextContains('[data-test="security-two-factor-status"]', 'Off');
        self::assertSelectorExists('a[data-test="security-two-factor"][href="/settings/two-factor"]');
    }

    public function testTheLoginLinkPageHintsAtTwoFactor(): void
    {
        $this->signIn($this->createUser());

        $this->client->request(Request::METHOD_GET, '/settings/login-link');

        self::assertSelectorExists('[data-test="login-link-two-factor-hint"] a[href="/settings/two-factor"]');
    }

    public function testSetupShowsAQrCodeAndKeepsTheSecretOnReload(): void
    {
        $this->signIn($this->createUser());

        $this->client->request(Request::METHOD_GET, '/settings/two-factor');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test="two-factor-status"]', 'Off');
        self::assertSelectorExists('[data-test="two-factor-setup"] img[src^="data:image/svg+xml;base64,"]');
        $secret = $this->shownSecret();

        $this->client->request(Request::METHOD_GET, '/settings/two-factor');
        self::assertSame($secret, $this->shownSecret());
    }

    public function testWrongFirstCodeLeavesTwoFactorOff(): void
    {
        $user = $this->createUser();
        $this->signIn($user);
        $this->client->request(Request::METHOD_GET, '/settings/two-factor');
        $secret = $this->shownSecret();

        $this->submit('Switch on', $this->otherCodeThan(TOTP::createFromSecret($secret)->now()));

        self::assertResponseRedirects('/settings/two-factor');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'The code is not correct.');
        self::assertFalse($this->reload($user)->isTotpAuthenticationEnabled());
    }

    public function testFirstCodeSwitchesItOnAndShowsTenBackupCodesOnce(): void
    {
        $user = $this->createUser();
        $this->signIn($user);
        $this->client->request(Request::METHOD_GET, '/settings/two-factor');
        $secret = $this->shownSecret();

        $this->submit('Switch on', TOTP::createFromSecret($secret)->now());

        self::assertResponseRedirects('/settings/two-factor/backup-codes');
        $this->client->followRedirect();
        $codes = $this->client->getCrawler()->filter('[data-test="two-factor-backup-codes"] li')->each(
            static fn ($node): string => trim($node->text()),
        );
        self::assertCount(10, $codes);
        self::assertCount(2, $this->client->getCrawler()->filter('[data-test="two-factor-backup-codes"] ul'));
        self::assertSelectorExists('[data-test="two-factor-backup-codes"][data-copy-to-clipboard-target="source"]');
        self::assertSelectorExists('[data-test="two-factor-copy-codes"]');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $stored = $this->reload($user);
        self::assertTrue($stored->isTotpAuthenticationEnabled());
        self::assertSame(10, $stored->countBackupCodes());
        self::assertTrue($stored->isBackupCode($codes[3]));
        self::assertSame($secret, self::getContainer()->get(TwoFactorSecretCipher::class)->decrypt((string) $stored->getTotpSecretEncrypted()));

        // Shown once: the page leads back to the settings afterwards.
        $this->client->request(Request::METHOD_GET, '/settings/two-factor/backup-codes');
        self::assertResponseRedirects('/settings/two-factor');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test="two-factor-status"]', 'On');
        self::assertSelectorTextContains('[data-test="two-factor-backup-codes-left"]', '10 of 10 backup codes left.');
    }

    public function testRenewingBackupCodesReplacesTheOldOnes(): void
    {
        $user = $this->createUserWithTwoFactor(['OLDCODEAAA']);
        $this->signIn($user);
        $this->client->request(Request::METHOD_GET, '/settings/two-factor');

        $this->submit('Create new backup codes', $this->currentCode());

        self::assertResponseRedirects('/settings/two-factor/backup-codes');
        $this->client->followRedirect();
        self::assertCount(10, $this->client->getCrawler()->filter('[data-test="two-factor-backup-codes"] li'));
        $stored = $this->reload($user);
        self::assertSame(10, $stored->countBackupCodes());
        self::assertFalse($stored->isBackupCode('OLDCODEAAA'));
    }

    public function testAWrongCodeKeepsItOn(): void
    {
        $user = $this->createUserWithTwoFactor();
        $this->signIn($user);
        $this->client->request(Request::METHOD_GET, '/settings/two-factor');

        $this->submit('Switch off', $this->otherCodeThan($this->currentCode()));

        self::assertResponseRedirects('/settings/two-factor');
        self::assertTrue($this->reload($user)->isTotpAuthenticationEnabled());
    }

    public function testACurrentCodeSwitchesItOff(): void
    {
        $user = $this->createUserWithTwoFactor();
        $this->signIn($user);
        $this->client->request(Request::METHOD_GET, '/settings/two-factor');

        $this->submit('Switch off', $this->currentCode());

        self::assertResponseRedirects('/settings/two-factor');
        $stored = $this->reload($user);
        self::assertFalse($stored->isTotpAuthenticationEnabled());
        self::assertNull($stored->getTotpSecretEncrypted());
        self::assertSame(0, $stored->countBackupCodes());
    }

    public function testAdministratorsCannotSwitchItOffWhileTheObligationIsOn(): void
    {
        $this->requireTwoFactorForAdmins();
        $admin = $this->createUserWithTwoFactor(admin: true);
        $this->signIn($admin);
        // A valid token from the renewal form, so the 403 comes from the obligation and not from the CSRF check.
        $csrfToken = $this->client->request(Request::METHOD_GET, '/settings/two-factor')->filter('input[name="_csrf_token"]')->attr('value');

        $this->client->request(Request::METHOD_POST, '/settings/two-factor/disable', ['_csrf_token' => $csrfToken, 'code' => $this->currentCode()]);

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->reload($admin)->isTotpAuthenticationEnabled());
    }

    public function testTheSwitchOffIsNotOfferedToAdministratorsWhileTheObligationIsOn(): void
    {
        $this->requireTwoFactorForAdmins();
        $this->signIn($this->createUserWithTwoFactor(admin: true));

        $this->client->request(Request::METHOD_GET, '/settings/two-factor');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-test="two-factor-disable-section"]');
    }

    public function testBackupCodeCanSwitchItOff(): void
    {
        $user = $this->createUserWithTwoFactor(['QWERTZUIPA']);
        $this->signIn($user);
        $this->client->request(Request::METHOD_GET, '/settings/two-factor');

        $this->submit('Switch off', 'qwert-zuipa');

        self::assertFalse($this->reload($user)->isTotpAuthenticationEnabled());
    }

    public function testCodesInTheSettingsAreRateLimited(): void
    {
        $user = $this->createUserWithTwoFactor();
        $this->signIn($user);
        // The limit is used up beforehand instead of by five wrong codes; the replaced limiter only lives as long
        // as the kernel, so the client must not reboot it between requests.
        $this->client->disableReboot();
        $this->denyRateLimiter('limiter.two_factor_settings_code', (string) $user->getId());

        $this->client->request(Request::METHOD_GET, '/settings/two-factor');
        $this->submit('Switch off', $this->currentCode());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert', 'Too many attempts.');
        self::assertTrue($this->reload($user)->isTotpAuthenticationEnabled());
    }

    public function testChangesNeedAValidCsrfToken(): void
    {
        $user = $this->createUserWithTwoFactor();
        $this->signIn($user);

        $this->client->request(Request::METHOD_POST, '/settings/two-factor/disable', [
            '_csrf_token' => 'invalid',
            'code' => $this->currentCode(),
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->reload($user)->isTotpAuthenticationEnabled());
    }

    public function testRememberedSessionsMustConfirmThePasswordFirst(): void
    {
        $this->createUser();
        $this->loginWithRememberMe($this->client, 'settings-user');
        $cookies = $this->extractRememberMeSessionCookies($this->client);
        $this->useRememberMeSessionOnly($this->client, $cookies['rememberMe'], $cookies['consentSubject']);

        $this->client->request(Request::METHOD_GET, '/settings/two-factor');

        self::assertResponseRedirects('/login/confirm');
    }

    private function createUser(bool $admin = false): User
    {
        $factory = UserFactory::new([
            'username' => 'settings-user',
            'email' => 'settings-user@example.test',
            'isVerified' => true,
            'loginLinkEnabled' => true,
        ]);

        return ($admin ? $factory->asAdmin() : $factory)->create();
    }

    /**
     * @param list<string> $backupCodes
     */
    private function createUserWithTwoFactor(array $backupCodes = ['ABCDEFGHJK'], bool $admin = false): User
    {
        $user = $this->createUser($admin);
        $user->enableTotpAuthentication(
            self::getContainer()->get(TwoFactorSecretCipher::class)->encrypt(self::SECRET),
            self::SECRET,
            // Lowest bcrypt cost: the default cost makes every hash and every check take about 0.25 s.
            array_map(static fn (string $code): string => password_hash($code, \PASSWORD_BCRYPT, ['cost' => 4]), $backupCodes),
            new \DateTimeImmutable('2026-11-22 12:00:00'),
        );
        $this->entityManager()->flush();

        return $user;
    }

    /** The obligation is off in the test environment; this client gets a policy with it switched on. */
    private function requireTwoFactorForAdmins(): void
    {
        $this->client->disableReboot();
        $container = self::getContainer();
        $container->set(TwoFactorAvailability::class, new TwoFactorAvailability($container->get(TwoFactorSecretCipher::class), false, true));
    }

    private function signIn(User $user): void
    {
        $this->client->loginUser($user);
    }

    private function submit(string $button, string $code): void
    {
        $form = $this->client->getCrawler()->selectButton($button)->form(['code' => $code]);
        $this->client->submit($form);
    }

    private function shownSecret(): string
    {
        return str_replace(' ', '', $this->client->getCrawler()->filter('[data-test="two-factor-secret"]')->text());
    }

    private function currentCode(): string
    {
        return TOTP::createFromSecret(self::SECRET)->now();
    }

    private function otherCodeThan(string $code): string
    {
        return '000000' === $code ? '111111' : '000000';
    }

    private function reload(User $user): User
    {
        $this->entityManager()->clear();
        $stored = $this->entityManager()->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $stored);

        return $stored;
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
