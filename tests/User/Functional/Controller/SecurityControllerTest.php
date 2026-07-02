<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use App\Tests\Support\Browser\CookieConsentTestHelper;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class SecurityControllerTest extends WebTestCase
{
    use CookieConsentTestHelper;
    use Factories;
    use HasBrowser;

    protected function setUp(): void
    {
        parent::setUp();
        self::createClient()->getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

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
            ->assertSeeIn('.alert.alert-danger', 'Invalid credentials.')
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
            ->assertSeeIn('.alert.alert-danger', 'Invalid credentials.')
            ->assertSee('Login')
        ;
    }

    public function testWrongPasswordShowsInvalidCredentialsError(): void
    {
        UserFactory::new(['username' => 'login-user'])->create();

        $this->acceptEssentialCookies($this->browser())
            ->visit('/login')
            ->fillField('Username', 'login-user')
            ->fillField('Password', 'wrong-password')
            ->click('Sign in')
            ->assertSuccessful()
            ->assertSeeIn('.alert.alert-danger', 'Invalid credentials.')
            ->assertSee('Login')
            ->use(static function (Crawler $crawler): void {
                self::assertSame('login-user', $crawler->filter('input[name="login[username]"]')->attr('value'));
            })
        ;
    }

    public function testGuestCannotAccessProtectedRouteWithoutLogin(): void
    {
        $this->acceptEssentialCookies($this->browser())
            ->visit('/settings')
            ->assertSeeIn('h2', 'Login')
            ->assertNotSeeElement('#user_name')
        ;
    }

    public function testLoginRedirectsToOriginallyRequestedProtectedRoute(): void
    {
        UserFactory::new(['username' => 'redirect-user'])->create();

        $this->acceptEssentialCookies($this->browser())
            ->visit('/settings')
            ->assertSeeIn('h2', 'Login')
            ->fillField('Username', 'redirect-user')
            ->fillField('Password', 'password')
            ->click('Sign in')
            ->assertSuccessful()
            ->assertSee('Account Settings')
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

    public function testLoginPasswordFieldHasAccessibleVisibilityToggle(): void
    {
        $this->browser()
            ->visit('/login')
            ->assertSuccessful()
            ->assertSeeElement('input[name="login[password]"][type="password"]')
            ->assertSeeElement('[data-testid="password-toggle-login_password"]')
            ->use(static function (Crawler $crawler): void {
                $input = $crawler->filter('input[name="login[password]"]');
                $toggle = $crawler->filter('[data-testid="password-toggle-login_password"]');

                self::assertSame('login[password]', $input->attr('name'));
                self::assertNull($input->attr('autocomplete'));
                self::assertSame('password', $input->attr('type'));
                self::assertSame('button', $toggle->attr('type'));
                self::assertSame('false', $toggle->attr('aria-pressed'));
                self::assertSame('login_password', $toggle->attr('aria-controls'));
                self::assertNotEmpty($toggle->attr('aria-label'));
                self::assertSame('Show password', $toggle->attr('aria-label'));
            })
        ;
    }
}
