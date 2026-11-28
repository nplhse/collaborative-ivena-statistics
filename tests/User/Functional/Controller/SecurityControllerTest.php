<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use App\Tests\Support\Browser\CookieConsentTestHelper;
use App\User\Domain\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The password sign-in over HTTP: cookie decision, success and logout, refused sign-ins, the redirect to the requested
 * page, throttling and the end of a session whose account was disabled.
 */
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
            ->fillField('Username or email', 'foo')
            ->fillField('Password', 'password')
            ->click('Sign in')
            ->assertSuccessful()
            ->assertSeeIn('.alert.alert-danger', 'Please confirm your cookie settings before signing in.')
            ->assertNotSeeElement('#user_name')
        ;
    }

    public function testYouCanLoginAndLogoutAfterEssentialConsent(): void
    {
        UserFactory::new(['username' => 'foo'])->create();

        $this->loginWithConsent($this->browser(), 'foo')
            ->assertSeeIn('#user_name', 'foo')
            ->assertNotSee('Login')
            ->visit('/logout')
            ->assertNotSeeElement('#user_name')
        ;
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function rejectedSignIns(): iterable
    {
        yield 'wrong password' => [[], 'alice', 'wrong-password'];
        yield 'unknown email' => [[], 'nobody@example.test', 'password'];
        yield 'disabled account' => [['isEnabled' => false], 'alice', 'password'];
        yield 'unverified account' => [['isVerified' => false], 'alice@example.test', 'password'];
    }

    /**
     * Every refused sign-in gets the same message, so the page does not reveal which accounts exist or why one is
     * refused, and keeps what was entered in the identifier field. Which account an identifier names:
     * LoginFormAuthenticatorTest; why disabled or unverified accounts are refused: UserAccountStatusCheckerTest.
     *
     * @param array<string, mixed> $account
     */
    #[DataProvider('rejectedSignIns')]
    public function testARefusedSignInShowsTheSameErrorAndKeepsTheIdentifier(array $account, string $identifier, string $password): void
    {
        UserFactory::createOne($account + ['username' => 'alice', 'email' => 'alice@example.test']);

        $browser = $this->loginWithConsent($this->browser(), $identifier, $password);

        $browser
            ->assertSeeIn('.alert.alert-danger', 'Invalid credentials.')
            ->assertNotSeeElement('#user_name')
            ->use(static function (Crawler $crawler) use ($identifier): void {
                self::assertSame($identifier, $crawler->filter('input[name="login[username]"]')->attr('value'));
            })
        ;
    }

    public function testLoginRedirectsToOriginallyRequestedProtectedRoute(): void
    {
        UserFactory::new(['username' => 'redirect-user'])->create();

        $this->acceptEssentialCookies($this->browser())
            ->visit('/settings')
            ->assertSeeIn('h2', 'Login')
            ->fillField('Username or email', 'redirect-user')
            ->fillField('Password', 'password')
            ->click('Sign in')
            ->assertSuccessful()
            ->assertOn('/settings')
            ->assertSeeElement('[data-testid="settings-overview"]')
        ;
    }

    public function testLoginIsRateLimitedAfterMaxAttempts(): void
    {
        UserFactory::new(['username' => 'throttle-user'])->create();

        $browser = $this->acceptEssentialCookies($this->browser());

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $browser
                ->visit('/login')
                ->fillField('Username or email', 'throttle-user')
                ->fillField('Password', 'wrong-password')
                ->click('Sign in')
                ->assertSuccessful()
            ;
        }

        $browser
            ->visit('/login')
            ->fillField('Username or email', 'throttle-user')
            ->fillField('Password', 'wrong-password')
            ->click('Sign in')
            ->assertSuccessful()
            ->assertSee('Too many failed login attempts')
        ;
    }

    public function testUserCanLoginWithEmailInAnyCase(): void
    {
        UserFactory::new(['username' => 'mail-user', 'email' => 'mail-user@example.test'])->create();

        $this->loginWithConsent($this->browser(), '  Mail-User@Example.TEST ')
            ->assertSeeIn('#user_name', 'mail-user')
        ;
    }

    public function testLoginPageAnnouncesTheEmailOnlyLoginOfVersionOne(): void
    {
        $this->browser()
            ->visit('/login')
            ->assertSuccessful()
            ->assertSee('Username or email')
            ->assertSee('Please note that from version 1.0, only the email address can be used to sign in.')
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
