<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use App\Tests\Support\Browser\CookieConsentTestHelper;
use App\User\Domain\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Browser\KernelBrowser;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class SettingsControllerTest extends WebTestCase
{
    use CookieConsentTestHelper;
    use Factories;
    use HasBrowser;

    public function testSettingsOverviewShowsTilesInsideTheSettingsCard(): void
    {
        UserFactory::new([
            'email' => 'settings@example.test',
            'isVerified' => true,
            'username' => 'settings-user',
        ])->create();

        $this->signedInAs('settings-user')
            ->visit('/settings')
            ->assertSuccessful()
            ->assertSeeIn('h2', 'Settings')
            ->assertNotSeeElement('select[name="settings_locale[locale]"]')
            ->assertSeeIn('[data-testid="settings-section-nav"] [aria-current="page"]', 'Overview')
            ->assertNotSeeElement('[data-testid="settings-back-to-overview"]')
            // #1508: password, sign-in link and two-factor authentication sit below "Sign-in & security".
            ->assertElementCount('[data-testid="settings-overview"] a.settings-tile', 4)
            ->assertElementAttributeContains('[data-testid="settings-tile-account"]', 'href', '/settings/account')
            ->assertElementAttributeContains('[data-testid="settings-tile-email"]', 'href', '/settings/email')
            ->assertElementAttributeContains('[data-testid="settings-tile-security"]', 'href', '/settings/security')
            ->assertSeeIn('[data-testid="settings-tile-security"]', 'Sign-in & security')
            // Its sub-items open only on its own pages; here a chevron hints at them.
            ->assertNotSeeElement('[data-testid^="settings-subsection-"]')
            ->assertSeeElement('[data-testid="settings-section-security"]')
            ->assertElementAttributeContains('[data-testid="settings-tile-notifications"]', 'href', '/settings/notifications')
            ->assertSeeIn('[data-testid="settings-tile-account"]', 'My Account')
            ->assertSeeIn('[data-testid="settings-tile-account"]', 'Display name, email verification status and language.')
            ->assertSeeIn('[data-testid="settings-tile-notifications"]', 'Notifications')
            ->visit('/settings/security')
            ->assertElementAttributeContains('[data-testid="settings-subsection-password"]', 'href', '/settings/password')
            ->assertElementAttributeContains('[data-testid="settings-subsection-login_link"]', 'href', '/settings/login-link')
            ->assertElementAttributeContains('[data-testid="settings-subsection-two_factor"]', 'href', '/settings/two-factor')
            ->visit('/settings/two-factor')
            ->assertSeeElement('[data-testid="settings-subsection-two_factor"][aria-current="page"]')
        ;
    }

    public function testSettingsOverviewTilesLeadToWorkingPages(): void
    {
        UserFactory::new([
            'email' => 'settings-tiles@example.test',
            'isVerified' => true,
            'username' => 'settings-tiles-user',
        ])->create();

        $browser = $this->signedInAs('settings-tiles-user');

        foreach (['account' => 'Account Settings', 'email' => 'Change email', 'security' => 'Sign-in & security', 'notifications' => 'Notifications'] as $tile => $heading) {
            $browser
                ->visit('/settings')
                ->click(sprintf('[data-testid="settings-tile-%s"]', $tile))
                ->assertSuccessful()
                ->assertSeeIn('h2', $heading)
            ;
        }
    }

    public function testSettingsOverviewIsShownInGerman(): void
    {
        UserFactory::new([
            'email' => 'settings-de@example.test',
            'isVerified' => true,
            'username' => 'settings-de-user',
        ])->create();

        $this->signedInAs('settings-de-user')
            ->visit('/locale/switch/de?_target_path=/settings')
            ->assertOn('/settings')
            ->assertSuccessful()
            ->assertSeeIn('h2', 'Einstellungen')
            ->assertSeeIn('[data-testid="settings-tile-account"]', 'Mein Konto')
            ->assertSeeIn('[data-testid="settings-tile-notifications"]', 'Legen Sie fest, welche Benachrichtigungen Sie wie erhalten.')
        ;
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function settingsSectionPages(): iterable
    {
        yield 'account' => ['/settings/account', 'My Account'];
        yield 'email' => ['/settings/email', 'Change email'];
        yield 'security' => ['/settings/security', 'Sign-in & security'];
        yield 'password' => ['/settings/password', 'Set new password'];
        yield 'login link' => ['/settings/login-link', 'Sign in with a link'];
        yield 'notifications' => ['/settings/notifications', 'Notifications'];
    }

    #[DataProvider('settingsSectionPages')]
    public function testSettingsPageMarksItsSectionAndLinksBackToOverview(string $path, string $activeSection): void
    {
        UserFactory::new([
            'email' => 'settings-section@example.test',
            'isVerified' => true,
            'username' => 'settings-section-user',
        ])->create();

        $this->signedInAs('settings-section-user')
            ->visit($path)
            ->assertSuccessful()
            ->assertSeeIn('[data-testid="settings-section-nav"] [aria-current="page"]', $activeSection)
            ->assertElementCount('[data-testid="settings-section-nav"] [aria-current="page"]', 1)
            ->assertElementAttributeContains('[data-testid="settings-back-to-overview"]', 'href', '/settings')
            ->click('All settings')
            ->assertOn('/settings')
            ->assertSeeElement('[data-testid="settings-overview"]')
        ;
    }

    public function testAccountPageShowsProfileDetailsAndLanguageForm(): void
    {
        UserFactory::new([
            'email' => 'settings-account@example.test',
            'isVerified' => true,
            'username' => 'settings-account-user',
        ])->create();

        $this->signedInAs('settings-account-user')
            ->visit('/settings/account')
            ->assertSuccessful()
            ->assertSeeIn('h2', 'Account Settings')
            ->assertSee('Profile Details')
            ->assertNotSeeElement('input[value="settings-account-user"]')
            ->assertSeeElement('input[name="settings_profile[displayName]"]')
            ->assertSeeElement('input[value="settings-account@example.test"][readonly]')
            ->assertSeeIn('[data-test="email-login-notice"]', 'From version 1.0, you sign in with your email address only.')
            ->assertSeeElement('select[name="settings_locale[locale]"]')
            ->assertNotSeeElement('.card-body a[href="/settings/password"].btn')
            // #1508: one section per setting, each form with its own save button, no shared footer.
            ->assertSeeElement('[data-testid="settings-section-profile"] form#settings-profile-form button[type="submit"]')
            ->assertSeeElement('[data-testid="settings-section-email"] input[value="settings-account@example.test"]')
            ->assertSeeElement('[data-testid="settings-section-language"] form#settings-locale-form select[name="settings_locale[locale]"]')
            ->assertSeeElement('[data-testid="settings-section-language"] form#settings-locale-form button[type="submit"]')
            ->assertNotSeeElement('.card-footer')
            ->assertNotSeeElement('[data-test="login-link-setting"]')
        ;
    }

    public function testUnverifiedUserSeesResendVerificationAction(): void
    {
        UserFactory::new([
            'email' => 'unverified-settings@example.test',
            'isVerified' => false,
            'username' => 'unverified-settings-user',
        ])->create();

        $this->signedInAs('unverified-settings-user')
            ->visit('/settings/account')
            ->assertSuccessful()
            ->assertSee('Resend verification email')
        ;
    }

    public function testResendVerificationIsRateLimited(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $username = sprintf('limited-settings-user-%s', $suffix);
        $email = sprintf('limited-settings-%s@example.test', $suffix);

        UserFactory::new([
            'email' => $email,
            'isVerified' => false,
            'username' => $username,
        ])->create();

        $browser = $this->signedInAs($username);
        $browser
            ->visit('/settings/account')
            ->assertSuccessful()
        ;

        $browser->click('Resend verification email')->assertSee('Verification email sent.');
        $browser->click('Resend verification email')->assertSee('Verification email sent.');
        $browser->click('Resend verification email')->assertSee('Verification email sent.');
        $browser->click('Resend verification email')->assertSee('Please wait before requesting another verification email.');
    }

    public function testResendVerificationForVerifiedUserReturnsToAccountPage(): void
    {
        $user = UserFactory::new([
            'email' => 'resend-verified@example.test',
            'isVerified' => false,
            'username' => 'resend-verified-user',
        ])->create();

        $browser = $this->signedInAs('resend-verified-user');
        $browser->visit('/settings/account')->assertSee('Resend verification email');

        \Zenstruck\Foundry\Persistence\refresh($user);
        $user->setIsVerified(true);
        \Zenstruck\Foundry\Persistence\save($user);

        $browser
            ->click('Resend verification email')
            ->assertOn('/settings/account')
            ->assertSee('Your email is already verified.')
        ;
    }

    public function testEmailChangeReturnsToAccountPage(): void
    {
        $user = UserFactory::new([
            'email' => 'email-change@example.test',
            'isVerified' => true,
            'username' => 'email-change-user',
        ])->create();

        $this->signedInAs('email-change-user')
            ->visit('/settings/email')
            ->fillField('settings_email[email]', 'email-changed@example.test')
            ->click('Save email')
            ->assertOn('/settings/account')
            ->assertSee('Email address updated. Please verify your new email.')
            ->assertSeeElement('input[value="email-changed@example.test"][readonly]')
        ;

        \Zenstruck\Foundry\Persistence\refresh($user);
        self::assertSame('email-changed@example.test', $user->getEmail());
        self::assertFalse($user->isVerified());
    }

    public function testPasswordChangeReturnsToAccountPage(): void
    {
        UserFactory::new([
            'email' => 'pwd-change@example.test',
            'isVerified' => true,
            'username' => 'pwd-change-user',
        ])->create();

        $this->signedInAs('pwd-change-user')
            ->visit('/settings/password')
            ->fillField('Current password', 'password')
            ->fillField('New password', 'Gr33n-Valley-Lantern-42!')
            ->fillField('Repeat new password', 'Gr33n-Valley-Lantern-42!')
            ->click('Save password')
            ->assertOn('/settings/account')
            ->assertSee('Password updated.')
        ;
    }

    public function testRememberMeUserIsRedirectedToConfirmPasswordForPasswordPage(): void
    {
        UserFactory::new([
            'email' => 'remember-settings@example.test',
            'isVerified' => true,
            'username' => 'remember-settings-user',
        ])->create();

        $client = $this->browser()->client();
        $this->loginWithRememberMe($client, 'remember-settings-user');
        $cookies = $this->extractRememberMeSessionCookies($client);
        $this->useRememberMeSessionOnly($client, $cookies['rememberMe'], $cookies['consentSubject']);

        $client->request(Request::METHOD_GET, '/settings');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Settings');

        $client->request(Request::METHOD_GET, '/settings/password');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Confirm your password');
    }

    public function testAuthenticatedUserCanOpenChangeEmailPage(): void
    {
        UserFactory::new([
            'email' => 'email-page@example.test',
            'isVerified' => true,
            'username' => 'email-page-user',
        ])->create();

        $this->signedInAs('email-page-user')
            ->visit('/settings/email')
            ->assertSuccessful()
            ->assertSee('Change email')
        ;
    }

    public function testAuthenticatedUserCanOpenChangePasswordPage(): void
    {
        UserFactory::new([
            'email' => 'pwd-page@example.test',
            'isVerified' => true,
            'username' => 'pwd-page-user',
        ])->create();

        $this->signedInAs('pwd-page-user')
            ->visit('/settings/password')
            ->assertSuccessful()
            ->assertSee('Change password')
            ->assertSee('Password strength')
            ->assertSeeElement('[data-controller*="password-strength"]')
        ;
    }

    public function testPasswordChangeWithWrongCurrentPasswordShowsFlash(): void
    {
        UserFactory::new([
            'email' => 'pwd-wrong@example.test',
            'isVerified' => true,
            'username' => 'pwd-wrong-user',
        ])->create();

        $this->signedInAs('pwd-wrong-user')
            ->visit('/settings/password')
            ->fillField('Current password', 'not-the-real-password')
            ->fillField('New password', 'newpass-123456')
            ->fillField('Repeat new password', 'newpass-123456')
            ->click('Save password')
            ->assertSuccessful()
            ->assertSee('Current password is invalid.')
        ;
    }

    public function testForcePasswordChangeShowsStrengthFeedbackUi(): void
    {
        UserFactory::new([
            'credentialsExpired' => true,
            'email' => 'force-pwd-ui@example.test',
            'isVerified' => true,
            'username' => 'force-pwd-ui-user',
        ])->create();

        // A real sign-in: the login sends users with expired credentials to the forced change.
        $this->loginWithConsent($this->browser(), 'force-pwd-ui-user')
            ->assertSuccessful()
            ->assertSee('Set a new password')
            ->assertSee('Password strength')
            ->assertSeeElement('[data-controller*="password-strength"]')
        ;
    }

    public function testUserCanChangeLanguageOnAccountSettings(): void
    {
        $user = UserFactory::new([
            'email' => 'locale-settings@example.test',
            'isVerified' => true,
            'username' => 'locale-settings-user',
            'locale' => null,
        ])->create();

        $browser = $this->signedInAs('locale-settings-user');
        $browser
            ->visit('/settings/account')
            ->assertSuccessful()
            ->assertSee('Language')
            ->selectFieldOption('settings_locale[locale]', 'de')
            ->click('Save')
            ->assertSuccessful()
            ->assertOn('/settings/account')
            ->assertSee('Spracheinstellung gespeichert.')
        ;

        \Zenstruck\Foundry\Persistence\refresh($user);
        self::assertSame('de', $user->getLocale());
    }

    public function testLanguageSettingsDropdownMarksAutomaticDefault(): void
    {
        UserFactory::new([
            'email' => 'locale-default@example.test',
            'isVerified' => true,
            'username' => 'locale-default-user',
            'locale' => 'en',
        ])->create();

        $this->signedInAs('locale-default-user')
            ->visit('/settings/account')
            ->assertSuccessful()
            ->assertSee('English (automatic default)')
        ;
    }

    public function testUserCanSetAndClearTheDisplayName(): void
    {
        $user = UserFactory::new([
            'email' => 'display-name@example.test',
            'isVerified' => true,
            'username' => 'display-name-user',
        ])->create();

        $browser = $this->signedInAs('display-name-user');
        $browser
            ->visit('/settings/account')
            ->assertSuccessful()
            ->assertSeeElement('[data-testid="settings-profile-avatar"][data-avatar="fallback"]')
            ->fillField('settings_profile[displayName]', '  Michael   Müller ')
            ->click('Update name')
            ->assertSuccessful()
            ->assertOn('/settings/account')
            ->assertSee('Display name saved.')
            ->assertSeeIn('[data-testid="settings-profile-avatar"]', 'MM')
        ;

        \Zenstruck\Foundry\Persistence\refresh($user);
        self::assertSame('Michael Müller', $user->getDisplayName());
        self::assertNull($user->getLocale(), 'saving the name leaves the language untouched');

        $browser
            ->fillField('settings_profile[displayName]', '   ')
            ->click('Update name')
            ->assertSuccessful()
            ->assertSee('Display name saved.')
        ;

        \Zenstruck\Foundry\Persistence\refresh($user);
        self::assertNull($user->getDisplayName());
    }

    public function testDisplayNameLongerThanTheLimitIsRejected(): void
    {
        $user = UserFactory::new([
            'email' => 'display-name-long@example.test',
            'isVerified' => true,
            'username' => 'display-name-long',
            'displayName' => 'Ada Admin',
        ])->create();

        $this->signedInAs('display-name-long')
            ->visit('/settings/account')
            ->fillField('settings_profile[displayName]', str_repeat('a', 101))
            ->click('Update name')
            ->assertOn('/settings/account')
            ->assertNotSee('Display name saved.')
            ->assertSeeElement('#settings_profile_displayName.is-invalid')
        ;

        \Zenstruck\Foundry\Persistence\refresh($user);
        self::assertSame('Ada Admin', $user->getDisplayName());
    }

    /**
     * Signs the user in without the login form: these tests are about the settings pages, and the sign-in itself is
     * covered by SecurityControllerTest. The form would add the cookie banner and four requests to every test.
     */
    private function signedInAs(string $username): KernelBrowser
    {
        return $this->browser()->actingAs(UserFactory::find(['username' => $username]));
    }
}
