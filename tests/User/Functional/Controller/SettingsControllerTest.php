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
final class SettingsControllerTest extends WebTestCase
{
    use CookieConsentTestHelper;
    use Factories;
    use HasBrowser;

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
        $user = UserFactory::new([
            'email' => 'unverified-settings@example.test',
            'isVerified' => true,
            'username' => 'unverified-settings-user',
        ])->create();

        $browser = $this->loginWithConsent($this->browser(), 'unverified-settings-user');

        $user->setIsVerified(false);
        $user->_save();

        $browser
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

        $user = UserFactory::new([
            'email' => $email,
            'isVerified' => true,
            'username' => $username,
        ])->create();

        $browser = $this->loginWithConsent($this->browser(), $username);

        $user->setIsVerified(false);
        $user->_save();

        $browser
            ->visit('/settings')
            ->assertSuccessful()
        ;

        $browser->click('Resend verification email')->assertSee('Verification email sent.');
        $browser->click('Resend verification email')->assertSee('Verification email sent.');
        $browser->click('Resend verification email')->assertSee('Verification email sent.');
        $browser->click('Resend verification email')->assertSee('Please wait before requesting another verification email.');
    }

    public function testRememberMeUserCanOpenSettingsOverviewAndPasswordPage(): void
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
        self::assertSelectorTextContains('h2', 'Account Settings');

        $client->request(Request::METHOD_GET, '/settings/password');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Change password');
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
