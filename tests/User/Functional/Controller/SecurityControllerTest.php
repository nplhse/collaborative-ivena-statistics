<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use App\Tests\Support\Browser\CookieConsentTestHelper;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
class SecurityControllerTest extends WebTestCase
{
    use CookieConsentTestHelper;
    use Factories;
    use HasBrowser;

    public function testLoginIsBlockedWithoutConsentDecision(): void
    {
        UserFactory::new(['username' => 'foo'])->create();

        $this->browser()
            ->visit('/login')
            ->fillField('Username', 'foo')
            ->fillField('Password', 'password')
            ->click('Sign in')
            ->assertSuccessful()
            ->assertSeeIn('.alert.alert-danger', 'Please confirm your cookie settings before signing in.')
            ->assertNotSee('foo')
        ;
    }

    public function testYouCanLoginAndLogoutAfterEssentialConsent(): void
    {
        UserFactory::new(['username' => 'foo'])->create();

        $this->loginWithConsent($this->browser(), 'foo')
            ->assertSeeIn('#user_name', 'foo')
            ->assertNotSee('Login')
            ->visit('/logout')
            ->assertNotSee('foo')
        ;
    }

    public function testDisabledUserCannotLogin(): void
    {
        UserFactory::new([
            'isEnabled' => false,
            'username' => 'disabled-user',
        ])->create();

        $this->acceptEssentialCookies($this->browser())
            ->visit('/login')
            ->fillField('Username', 'disabled-user')
            ->fillField('Password', 'password')
            ->click('Sign in')
            ->assertSuccessful()
            ->assertSeeIn('.alert.alert-danger', 'Account is disabled.')
            ->assertSee('Login')
        ;
    }

    public function testUnverifiedUserCannotLogin(): void
    {
        UserFactory::new([
            'isVerified' => false,
            'username' => 'unverified-user',
        ])->create();

        $this->acceptEssentialCookies($this->browser())
            ->visit('/login')
            ->fillField('Username', 'unverified-user')
            ->fillField('Password', 'password')
            ->click('Sign in')
            ->assertSuccessful()
            ->assertSeeIn('.alert.alert-danger', 'Please confirm your email address before signing in.')
            ->assertSee('Login')
        ;
    }

    public function testLoginIsRateLimitedAfterMaxAttempts(): void
    {
        UserFactory::new(['username' => 'throttle-user'])->create();

        $browser = $this->acceptEssentialCookies($this->browser());

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $browser
                ->visit('/login')
                ->fillField('Username', 'throttle-user')
                ->fillField('Password', 'wrong-password')
                ->click('Sign in')
                ->assertSuccessful()
            ;
        }

        $browser
            ->visit('/login')
            ->fillField('Username', 'throttle-user')
            ->fillField('Password', 'wrong-password')
            ->click('Sign in')
            ->assertSuccessful()
            ->assertSee('Too many failed login attempts')
        ;
    }

    public function testDisabledUserIsLoggedOutOnNextRequest(): void
    {
        $user = UserFactory::new(['username' => 'session-user'])->create();

        $this->loginWithConsent($this->browser(), 'session-user')
            ->assertSeeIn('#user_name', 'session-user')
        ;

        \Zenstruck\Foundry\Persistence\refresh($user);
        $user->setIsEnabled(false);
        \Zenstruck\Foundry\Persistence\save($user);

        $this->browser()
            ->visit('/settings')
            ->assertSuccessful()
            ->assertSee('Login')
        ;
    }
}
