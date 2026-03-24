<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class SettingsControllerTest extends WebTestCase
{
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

        $this->browser()
            ->visit('/login')
            ->fillField('Username', 'settings-user')
            ->fillField('Password', 'password')
            ->click('Sign in')
            ->assertSuccessful()
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

        $this->browser()
            ->visit('/login')
            ->fillField('Username', 'unverified-settings-user')
            ->fillField('Password', 'password')
            ->click('Sign in')
            ->assertSuccessful()
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

        $browser = $this->browser()
            ->visit('/login')
            ->fillField('Username', $username)
            ->fillField('Password', 'password')
            ->click('Sign in')
            ->assertSuccessful()
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

        $this->browser()
            ->visit('/login')
            ->fillField('Username', 'email-page-user')
            ->fillField('Password', 'password')
            ->click('Sign in')
            ->assertSuccessful()
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

        $this->browser()
            ->visit('/login')
            ->fillField('Username', 'pwd-page-user')
            ->fillField('Password', 'password')
            ->click('Sign in')
            ->assertSuccessful()
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

        $this->browser()
            ->visit('/login')
            ->fillField('Username', 'pwd-wrong-user')
            ->fillField('Password', 'password')
            ->click('Sign in')
            ->assertSuccessful()
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
