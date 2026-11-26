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
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Two-factor authentication in the settings (#1357): setup with QR code and first code, backup codes shown once,
 * renewing them and switching off with a current code.
 */
#[ResetDatabase]
final class TwoFactorSettingsTest extends WebTestCase
{
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

    public function testSettingsListTheSectionAndTheLoginLinkHintPointsToIt(): void
    {
        $this->signIn($this->createUser());

        $this->client->request(Request::METHOD_GET, '/settings/security');
        self::assertSelectorExists('[data-testid="settings-section-nav"] a[href="/settings/two-factor"]');

        $this->client->request(Request::METHOD_GET, '/settings/login-link');
        self::assertSelectorExists('[data-test="login-link-two-factor-hint"] a[href="/settings/two-factor"]');

        // The overview of "Sign-in & security" shows the status of every sub-item and links to it (#1508).
        $this->client->request(Request::METHOD_GET, '/settings/security');
        // The password row offers the change; every account has one, so it shows no status badge.
        self::assertSelectorExists('a[data-test="security-password"][href="/settings/password"]');
        self::assertSelectorNotExists('[data-test="security-password"] .badge');
        self::assertSelectorTextContains('[data-test="security-login-link-status"]', 'On');
        self::assertSelectorTextContains('[data-test="security-two-factor-status"]', 'Off');
        self::assertSelectorExists('a[data-test="security-two-factor"][href="/settings/two-factor"]');
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
        self::assertCount(10, array_unique($codes));
        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z]{5}-[A-HJ-NP-Z]{5}$/', $codes[0]);
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

    public function testSwitchingOffNeedsACurrentCode(): void
    {
        $user = $this->createUserWithTwoFactor();
        $this->signIn($user);
        $this->client->request(Request::METHOD_GET, '/settings/two-factor');

        $this->submit('Switch off', $this->otherCodeThan($this->currentCode()));
        self::assertResponseRedirects('/settings/two-factor');
        self::assertTrue($this->reload($user)->isTotpAuthenticationEnabled());

        $this->client->followRedirect();
        $this->submit('Switch off', $this->currentCode());
        self::assertResponseRedirects('/settings/two-factor');
        $stored = $this->reload($user);
        self::assertFalse($stored->isTotpAuthenticationEnabled());
        self::assertNull($stored->getTotpSecretEncrypted());
        self::assertSame(0, $stored->countBackupCodes());
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
        $wrongCode = $this->otherCodeThan($this->currentCode());

        for ($i = 0; $i < 5; ++$i) {
            $this->client->request(Request::METHOD_GET, '/settings/two-factor');
            $this->submit('Switch off', $wrongCode);
        }

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

    private function createUser(): User
    {
        return UserFactory::new([
            'username' => 'settings-user',
            'email' => 'settings-user@example.test',
            'isVerified' => true,
            'loginLinkEnabled' => true,
        ])->create();
    }

    /**
     * @param list<string> $backupCodes
     */
    private function createUserWithTwoFactor(array $backupCodes = ['ABCDEFGHJK']): User
    {
        $user = $this->createUser();
        $user->enableTotpAuthentication(
            self::getContainer()->get(TwoFactorSecretCipher::class)->encrypt(self::SECRET),
            self::SECRET,
            array_map(static fn (string $code): string => password_hash($code, \PASSWORD_DEFAULT), $backupCodes),
            new \DateTimeImmutable('2026-11-22 12:00:00'),
        );
        $this->entityManager()->flush();

        return $user;
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
