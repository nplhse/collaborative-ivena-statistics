<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use App\Tests\Support\Browser\CookieConsentTestHelper;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class SettingsControllerTest extends WebTestCase
{
    use CookieConsentTestHelper;
    use Factories;
    use HasBrowser;
    use ResetDatabase;

    public function testAuthenticatedUserCanOpenSettingsOverview(): void
    {
        UserFactory::new([
            'email' => 'settings@example.test',
            'isVerified' => true,
            'username' => 'settings-user',
        ])->create();

        $this->loginWithConsent($this->browser(), 'settings-user')
            ->visit('/settings')
            ->assertSuccessful()
            ->assertSee('Account Settings')
        ;
    }

    public function testUnverifiedUserSeesResendVerificationAction(): void
    {
        UserFactory::new([
            'email' => 'unverified-settings@example.test',
            'isVerified' => false,
            'username' => 'unverified-settings-user',
        ])->create();

        $this->loginWithConsent($this->browser(), 'unverified-settings-user')
            ->visit('/settings')
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

        $browser = $this->loginWithConsent($this->browser(), $username)
            ->visit('/settings')
            ->assertSuccessful()
        ;

        $browser->click('Resend verification email')->assertSee('Verification email sent.');
        $browser->click('Resend verification email')->assertSee('Verification email sent.');
        $browser->click('Resend verification email')->assertSee('Verification email sent.');
        $browser->click('Resend verification email')->assertSee('Please wait before requesting another verification email.');
    }

    public function testAuthenticatedUserCanOpenChangeEmailPage(): void
    {
        UserFactory::new([
            'email' => 'email-page@example.test',
            'isVerified' => true,
            'username' => 'email-page-user',
        ])->create();

        $this->loginWithConsent($this->browser(), 'email-page-user')
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

        $this->loginWithConsent($this->browser(), 'pwd-page-user')
            ->visit('/settings/password')
            ->assertSuccessful()
            ->assertSee('Change password')
        ;
    }

    public function testPasswordChangeWithWrongCurrentPasswordShowsFlash(): void
    {
        UserFactory::new([
            'email' => 'pwd-wrong@example.test',
            'isVerified' => true,
            'username' => 'pwd-wrong-user',
        ])->create();

        $this->loginWithConsent($this->browser(), 'pwd-wrong-user')
            ->visit('/settings/password')
            ->fillField('Current password', 'not-the-real-password')
            ->fillField('New password', 'newpass-123456')
            ->fillField('Repeat new password', 'newpass-123456')
            ->click('Save password')
            ->assertSuccessful()
            ->assertSee('Current password is invalid.')
        ;
    }
}
