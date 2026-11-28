<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use App\Tests\Support\Browser\CookieConsentTestHelper;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\KernelBrowser;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

use function Zenstruck\Foundry\Persistence\refresh;
use function Zenstruck\Foundry\Persistence\save;

/**
 * The settings pages over HTTP: navigation between them and the changes they save.
 *
 * Lower down (#1348): which items the navigation shows is SettingsNavigationExtensionTest, the forms are
 * SettingsFormTypesTest and PasswordFormTypeTest, the password field is PasswordWidgetRenderingTest, and the
 * password confirmation for remembered sessions is ConfirmPasswordControllerTest.
 */
#[ResetDatabase]
final class SettingsControllerTest extends WebTestCase
{
    use CookieConsentTestHelper;
    use Factories;
    use HasBrowser;

    public function testSettingsOverviewShowsOneTilePerSection(): void
    {
        $browser = $this->signedIn();

        $browser->visit('/settings');

        $browser
            ->assertSuccessful()
            ->assertSeeIn('h2', 'Settings')
            ->assertSeeIn('[data-testid="settings-section-nav"] [aria-current="page"]', 'Overview')
            ->assertNotSeeElement('[data-testid="settings-back-to-overview"]')
            ->assertNotSeeElement('select[name="settings_locale[locale]"]')
            // #1508: password, sign-in link and two-factor authentication sit below "Sign-in & security".
            ->assertElementCount('[data-testid="settings-overview"] a.settings-tile', 4)
            ->assertElementAttributeContains('[data-testid="settings-tile-account"]', 'href', '/settings/account')
            ->assertElementAttributeContains('[data-testid="settings-tile-email"]', 'href', '/settings/email')
            ->assertElementAttributeContains('[data-testid="settings-tile-security"]', 'href', '/settings/security')
            ->assertElementAttributeContains('[data-testid="settings-tile-notifications"]', 'href', '/settings/notifications')
            // The sub-items of "Sign-in & security" open only on its own pages; here a chevron hints at them.
            ->assertNotSeeElement('[data-testid^="settings-subsection-"]')
            ->assertSeeElement('[data-testid="settings-section-security"]')
        ;
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function overviewTiles(): iterable
    {
        yield 'account' => ['account', 'Account Settings'];
        yield 'email' => ['email', 'Change email'];
        yield 'security' => ['security', 'Sign-in & security'];
        yield 'notifications' => ['notifications', 'Notifications'];
    }

    #[DataProvider('overviewTiles')]
    public function testEachOverviewTileLeadsToAWorkingPage(string $tile, string $heading): void
    {
        $browser = $this->signedIn()->visit('/settings');

        $browser->click(sprintf('[data-testid="settings-tile-%s"]', $tile));

        $browser->assertSuccessful()->assertSeeIn('h2', $heading);
    }

    public function testTheSecurityPagesListTheirSubsections(): void
    {
        $browser = $this->signedIn();

        $browser->visit('/settings/two-factor');

        $browser
            ->assertElementAttributeContains('[data-testid="settings-subsection-password"]', 'href', '/settings/password')
            ->assertElementAttributeContains('[data-testid="settings-subsection-login_link"]', 'href', '/settings/login-link')
            ->assertElementAttributeContains('[data-testid="settings-subsection-two_factor"]', 'href', '/settings/two-factor')
            ->assertSeeElement('[data-testid="settings-subsection-two_factor"][aria-current="page"]')
        ;
    }

    public function testSettingsOverviewIsShownInGerman(): void
    {
        $browser = $this->signedIn();

        $browser->visit('/locale/switch/de?_target_path=/settings');

        $browser
            ->assertOn('/settings')
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
        $browser = $this->signedIn();

        $browser->visit($path);

        $browser
            ->assertSuccessful()
            ->assertSeeIn('[data-testid="settings-section-nav"] [aria-current="page"]', $activeSection)
            ->assertElementCount('[data-testid="settings-section-nav"] [aria-current="page"]', 1)
            ->assertElementAttributeContains('[data-testid="settings-back-to-overview"]', 'href', '/settings')
        ;
    }

    /** #1508: one section per setting, each form with its own save button, no shared footer. */
    public function testAccountPageHasASectionPerSetting(): void
    {
        $browser = $this->signedIn();

        $browser->visit('/settings/account');

        $browser
            ->assertSuccessful()
            ->assertSeeElement('[data-testid="settings-section-profile"] form#settings-profile-form button[type="submit"]')
            ->assertSeeElement('[data-testid="settings-section-email"] input[value="alice@example.test"][readonly]')
            ->assertSeeElement('[data-testid="settings-section-language"] form#settings-locale-form button[type="submit"]')
            ->assertSeeIn('[data-test="email-login-notice"]', 'From version 1.0, you sign in with your email address only.')
            ->assertNotSeeElement('.card-footer')
        ;
    }

    public function testResendVerificationIsRateLimited(): void
    {
        $browser = $this->signedIn(['isVerified' => false])->visit('/settings/account');

        // 3 per hour: the fourth request is refused.
        $browser->click('Resend verification email')->assertSee('Verification email sent.');
        $browser->click('Resend verification email')->assertSee('Verification email sent.');
        $browser->click('Resend verification email')->assertSee('Verification email sent.');
        $browser->click('Resend verification email');

        $browser->assertSee('Please wait before requesting another verification email.');
    }

    /** The address was confirmed in another tab after the page was loaded. */
    public function testResendVerificationForVerifiedUserReturnsToAccountPage(): void
    {
        $browser = $this->signedIn(['isVerified' => false])->visit('/settings/account');
        $user = $this->user();
        $user->setIsVerified(true);
        save($user);

        $browser->click('Resend verification email');

        $browser
            ->assertOn('/settings/account')
            ->assertSee('Your email is already verified.')
        ;
    }

    public function testEmailChangeReturnsToAccountPage(): void
    {
        $browser = $this->signedIn()->visit('/settings/email');

        $browser
            ->fillField('settings_email[email]', 'alice-new@example.test')
            ->click('Save email')
        ;

        $browser
            ->assertOn('/settings/account')
            ->assertSee('Email address updated. Please verify your new email.')
            ->assertSeeElement('input[value="alice-new@example.test"][readonly]')
        ;
        $user = $this->user();
        self::assertSame('alice-new@example.test', $user->getEmail());
        self::assertFalse($user->isVerified());
    }

    public function testPasswordChangeReturnsToAccountPage(): void
    {
        $browser = $this->signedIn()->visit('/settings/password');

        $browser
            ->fillField('Current password', 'password')
            ->fillField('New password', 'Gr33n-Valley-Lantern-42!')
            ->fillField('Repeat new password', 'Gr33n-Valley-Lantern-42!')
            ->click('Save password')
        ;

        $browser
            ->assertOn('/settings/account')
            ->assertSee('Password updated.')
        ;
    }

    public function testPasswordChangeWithWrongCurrentPasswordShowsFlash(): void
    {
        $browser = $this->signedIn()->visit('/settings/password');

        $browser
            ->fillField('Current password', 'not-the-real-password')
            ->fillField('New password', 'newpass-123456')
            ->fillField('Repeat new password', 'newpass-123456')
            ->click('Save password')
        ;

        $browser
            ->assertSuccessful()
            ->assertSee('Current password is invalid.')
        ;
    }

    /** A real sign-in: the login sends users with expired credentials to the forced password change. */
    public function testExpiredCredentialsLeadToTheForcedPasswordChange(): void
    {
        UserFactory::createOne(['username' => 'alice', 'email' => 'alice@example.test', 'isVerified' => true, 'credentialsExpired' => true]);

        $browser = $this->loginWithConsent($this->browser(), 'alice');

        $browser
            ->assertOn('/settings/force-password-change')
            ->assertSee('Set a new password')
        ;
    }

    public function testUserCanChangeLanguageOnAccountSettings(): void
    {
        $browser = $this->signedIn(['locale' => null])->visit('/settings/account');

        $browser
            ->selectFieldOption('settings_locale[locale]', 'de')
            ->click('Save')
        ;

        $browser
            ->assertOn('/settings/account')
            ->assertSee('Spracheinstellung gespeichert.')
        ;
        self::assertSame('de', $this->user()->getLocale());
    }

    /** How the name is normalised: UserDisplayNameTest. */
    public function testUserCanSetTheDisplayName(): void
    {
        $browser = $this->signedIn()->visit('/settings/account');
        $browser->assertSeeElement('[data-testid="settings-profile-avatar"][data-avatar="fallback"]');

        $browser
            ->fillField('settings_profile[displayName]', 'Alice Adams')
            ->click('Update name')
        ;

        $browser
            ->assertOn('/settings/account')
            ->assertSee('Display name saved.')
            ->assertSeeIn('[data-testid="settings-profile-avatar"]', 'AA')
        ;
        $user = $this->user();
        self::assertSame('Alice Adams', $user->getDisplayName());
        self::assertNull($user->getLocale(), 'saving the name leaves the language untouched');
    }

    public function testUserCanClearTheDisplayName(): void
    {
        $browser = $this->signedIn(['displayName' => 'Alice Adams'])->visit('/settings/account');

        $browser
            ->fillField('settings_profile[displayName]', '   ')
            ->click('Update name')
        ;

        $browser->assertSee('Display name saved.');
        self::assertNull($this->user()->getDisplayName());
    }

    /**
     * Signs in as a verified user "alice" without the login form: these tests are about the settings pages, and the
     * sign-in itself is covered by SecurityControllerTest. The form would add four requests to every test.
     *
     * @param array<string, mixed> $attributes
     */
    private function signedIn(array $attributes = []): KernelBrowser
    {
        $user = UserFactory::createOne($attributes + ['username' => 'alice', 'email' => 'alice@example.test', 'isVerified' => true, 'locale' => null]);

        return $this->browser()->actingAs($user);
    }

    private function user(string $username = 'alice'): User
    {
        $user = UserFactory::find(['username' => $username]);
        refresh($user);

        return $user;
    }
}
