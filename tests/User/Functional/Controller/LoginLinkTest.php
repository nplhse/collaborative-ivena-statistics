<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use App\Tests\Support\Browser\CookieConsentTestHelper;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

use function Zenstruck\Foundry\Persistence\refresh;
use function Zenstruck\Foundry\Persistence\save;

/**
 * One-time login link (#1355): opt-in, same answer for every request, confirmation page, single use.
 */
#[ResetDatabase]
final class LoginLinkTest extends WebTestCase
{
    use CookieConsentTestHelper;
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->client->getContainer()->get('cache.rate_limiter')->clear();
        $this->client->getContainer()->get('cache.app')->clear();
    }

    public function testOptedInUserGetsALinkByEmail(): void
    {
        $this->createUser('link-user');

        $this->requestLink('Link-User@Example.TEST');

        self::assertResponseRedirects('/login/link/sent');
        self::assertEmailCount(1);
        $email = $this->onlyEmail();
        self::assertSame('link-user@example.test', $email->getTo()[0]->getAddress());
        self::assertSame('Your sign-in link', $email->getSubject());
        self::assertStringContainsString('valid for 15 minutes', (string) $email->getHtmlBody());
        self::assertStringContainsString('/login/link/check?', $this->linkFrom($email));
    }

    public function testUsernameWorksAsWell(): void
    {
        $this->createUser('link-user');

        $this->requestLink('link-user');

        self::assertEmailCount(1);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function ineligibleAccounts(): iterable
    {
        yield 'unknown email' => [[], 'nobody@example.test'];
        yield 'not opted in' => [['loginLinkEnabled' => false], 'link-user@example.test'];
        yield 'unverified' => [['isVerified' => false], 'link-user@example.test'];
        yield 'disabled' => [['isEnabled' => false], 'link-user@example.test'];
        yield 'administrator' => [['roles' => [UserRole::USER, UserRole::ADMIN]], 'link-user@example.test'];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    #[DataProvider('ineligibleAccounts')]
    public function testIneligibleAccountsGetTheSameAnswerButNoMail(array $attributes, string $identifier): void
    {
        $this->createUser('link-user', $attributes);

        $this->requestLink($identifier);

        self::assertResponseRedirects('/login/link/sent');
        self::assertEmailCount(0);
    }

    public function testSecondButtonOfTheLoginFormRequestsALinkWithoutPassword(): void
    {
        $this->createUser('link-user');
        $this->acceptEssentialCookiesOnly($this->client);

        $crawler = $this->client->request(Request::METHOD_GET, '/login');
        $form = $crawler->selectButton('Send me a sign-in link')->form(['login[username]' => 'link-user@example.test']);
        $this->client->submit($form);

        self::assertResponseRedirects('/login/link/sent');
        self::assertEmailCount(1);
    }

    public function testRequestsAreRateLimitedPerIdentifier(): void
    {
        $this->createUser('link-user');

        // 3 per identifier and 15 minutes: the fourth request answers the same but sends nothing.
        foreach ([1, 1, 1, 0] as $expectedMails) {
            $this->requestLink('link-user@example.test');
            self::assertResponseRedirects('/login/link/sent');
            self::assertEmailCount($expectedMails);
        }
    }

    public function testLinkOpensAConfirmationPageAndSignsInOnlyOnItsPost(): void
    {
        $this->createUser('link-user', ['displayName' => 'Linda Link']);
        $link = $this->linkFor('link-user@example.test');

        $crawler = $this->client->request(Request::METHOD_GET, $link);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Confirm sign-in');
        self::assertSelectorTextContains('[data-test="login-link-account"]', 'Linda Link');
        self::assertSelectorTextContains('[data-test="login-link-account"]', '@link-user');
        self::assertSelectorTextContains('[data-test="login-link-account"] [data-avatar="initials"]', 'LL');
        self::assertSelectorNotExists('#user_name');

        $this->client->submit($crawler->selectButton('Sign in')->form());
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('#user_name', 'link-user');
    }

    public function testLinkWorksOnlyOnce(): void
    {
        $this->createUser('link-user');
        $link = $this->linkFor('link-user@example.test');

        $this->confirm($link);
        $this->client->request(Request::METHOD_GET, '/logout');

        $this->confirm($link);
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert.alert-danger');
        self::assertSelectorNotExists('#user_name');
    }

    public function testLinkLoginOffersNoRememberMe(): void
    {
        $this->createUser('link-user');
        $link = $this->linkFor('link-user@example.test');

        $this->acceptEssentialCookiesOnly($this->client);
        $this->client->request(Request::METHOD_GET, $link);
        self::assertSelectorNotExists('input[name="_remember_me"]');

        $this->confirm($link);
        self::assertNull($this->client->getCookieJar()->get('REMEMBERME'));
    }

    public function testOptingOutInvalidatesOpenLinks(): void
    {
        $user = $this->createUser('link-user');
        $link = $this->linkFor('link-user@example.test');

        refresh($user);
        $user->setLoginLinkEnabled(false);
        save($user);

        $this->confirm($link);
        self::assertResponseRedirects('/login');
    }

    public function testPasswordChangeInvalidatesOpenLinks(): void
    {
        $user = $this->createUser('link-user');
        $link = $this->linkFor('link-user@example.test');

        refresh($user);
        $user->setPassword('changed-hash');
        save($user);

        $this->confirm($link);
        self::assertResponseRedirects('/login');
    }

    public function testExpiredLinkFails(): void
    {
        $user = $this->createUser('link-user');
        $this->acceptEssentialCookiesOnly($this->client);

        $requestStack = self::getContainer()->get(RequestStack::class);
        $requestStack->push(Request::create('/login'));
        $link = self::getContainer()->get(LoginLinkHandlerInterface::class)->createLoginLink($user, null, -60)->getUrl();
        $requestStack->pop();

        $this->confirm($link, acceptCookies: false);
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorNotExists('#user_name');
    }

    public function testSigningInNeedsACookieDecisionAndKeepsTheLinkUsable(): void
    {
        $this->createUser('link-user');
        $link = $this->linkFor('link-user@example.test');
        $this->client->getCookieJar()->clear();

        $crawler = $this->client->request(Request::METHOD_GET, $link);
        self::assertSelectorExists('.alert.alert-warning');
        $this->client->submit($crawler->selectButton('Sign in')->form());
        self::assertResponseRedirects('/login');

        $this->confirm($link);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#user_name', 'link-user');
    }

    public function testInvalidLinkShowsOnlyTheUsernameFromTheLink(): void
    {
        $this->createUser('link-user', ['displayName' => 'Linda Link']);
        $link = $this->linkFor('link-user@example.test');

        $this->client->request(Request::METHOD_GET, $link.'x');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test="login-link-account"]', 'Sign in as link-user?');
        self::assertSelectorTextNotContains('[data-test="login-link-account"]', 'Linda Link');
        self::assertSelectorNotExists('[data-avatar]');
    }

    public function testSignedInUsersAreSentToTheDashboard(): void
    {
        $this->client->loginUser($this->createUser('link-user'));

        $this->client->request(Request::METHOD_GET, '/login/link');

        self::assertResponseRedirects('/');
    }

    public function testRequestWithoutValidCsrfTokenIsDenied(): void
    {
        $this->createUser('link-user');

        $this->client->request(Request::METHOD_POST, '/login/link', [
            'identifier' => 'link-user@example.test',
            '_link_csrf_token' => 'invalid',
        ]);

        // Access denied for a visitor: the entry point sends them to the login page.
        self::assertResponseRedirects('/login');
        self::assertEmailCount(0);
    }

    public function testEmptyIdentifierShowsTheFormAgain(): void
    {
        $this->requestLink('   ');

        self::assertResponseRedirects('/login/link');
        self::assertEmailCount(0);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedLinks(): iterable
    {
        yield 'unknown user' => ['/login/link/check?user=nobody&expires=9999999999&hash=abc'];
        yield 'non-numeric expiry' => ['/login/link/check?user=link-user&expires=soon&hash=abc'];
    }

    #[DataProvider('malformedLinks')]
    public function testMalformedLinksShowOnlyTheUsername(string $link): void
    {
        $this->createUser('link-user', ['displayName' => 'Linda Link']);

        $this->client->request(Request::METHOD_GET, $link);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test="login-link-account"]', 'Sign in as');
        self::assertSelectorNotExists('[data-avatar]');
    }

    public function testCheckRouteWithoutParametersLeadsToTheLogin(): void
    {
        $this->client->request(Request::METHOD_GET, '/login/link/check');

        self::assertResponseRedirects('/login');
    }

    public function testUserSwitchesTheOptInInTheSettings(): void
    {
        $user = $this->createUser('link-user', ['loginLinkEnabled' => false]);
        $this->client->loginUser($user);

        // #1508: the opt-in has its own page below "Sign-in & security", with its status shown like two-factor authentication.
        $crawler = $this->client->request(Request::METHOD_GET, '/settings/login-link');
        self::assertSelectorTextContains('[data-test="login-link-setting"]', 'two-factor authentication');
        self::assertSelectorTextContains('[data-test="login-link-status"]', 'Off');
        self::assertSelectorTextContains('[data-test="login-link-state"]', 'The sign-in link is off.');
        self::assertSelectorExists('.alert[data-test="login-link-two-factor-hint"]');
        self::assertSelectorExists('form.card-footer [data-test="login-link-switch"].btn-success');

        $this->client->submit($crawler->selectButton('Switch on')->form());
        self::assertResponseRedirects('/settings/login-link');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test="login-link-status"]', 'On');
        self::assertSelectorTextContains('[data-test="login-link-state"]', 'The sign-in link is active.');
        self::assertSelectorExists('form.card-footer [data-test="login-link-switch"].btn-danger');

        $this->client->request(Request::METHOD_GET, '/settings/security');
        self::assertSelectorTextContains('[data-test="security-login-link-status"]', 'On');
        $crawler = $this->client->request(Request::METHOD_GET, '/settings/login-link');
        refresh($user);
        self::assertTrue($user->isLoginLinkEnabled());

        $this->client->submit($crawler->selectButton('Switch off')->form());
        refresh($user);
        self::assertFalse($user->isLoginLinkEnabled());
    }

    public function testSettingsSwitchNeedsAValidCsrfToken(): void
    {
        $user = $this->createUser('link-user', ['loginLinkEnabled' => false]);
        $this->client->loginUser($user);

        $this->client->request(Request::METHOD_POST, '/settings/login-link', ['enabled' => '1', '_csrf_token' => 'invalid']);

        self::assertResponseStatusCodeSame(403);
        refresh($user);
        self::assertFalse($user->isLoginLinkEnabled());
    }

    public function testAdministratorsDoNotGetTheOptIn(): void
    {
        $admin = $this->createUser('link-admin', ['loginLinkEnabled' => false, 'roles' => [UserRole::USER, UserRole::ADMIN]]);
        $this->client->loginUser($admin);

        $this->client->request(Request::METHOD_GET, '/settings/account');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-test="login-link-setting"]');
        $this->client->request(Request::METHOD_GET, '/settings/security');
        self::assertSelectorNotExists('[data-test="security-login-link"]');
        self::assertSelectorNotExists('[data-testid="settings-subsection-login_link"]');
        $this->client->request(Request::METHOD_GET, '/settings/login-link');
        self::assertResponseStatusCodeSame(404);

        $this->client->request(Request::METHOD_POST, '/settings/login-link', ['enabled' => '1']);
        self::assertResponseStatusCodeSame(403);
        refresh($admin);
        self::assertFalse($admin->isLoginLinkEnabled());
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createUser(string $username, array $attributes = []): User
    {
        return UserFactory::new(array_merge([
            'username' => $username,
            'email' => $username.'@example.test',
            'isVerified' => true,
            'loginLinkEnabled' => true,
        ], $attributes))->create();
    }

    private function requestLink(string $identifier): void
    {
        $this->acceptEssentialCookiesOnly($this->client);
        $crawler = $this->client->request(Request::METHOD_GET, '/login/link');
        $form = $crawler->selectButton('Send me a sign-in link')->form(['identifier' => $identifier]);
        $this->client->submit($form);
    }

    private function linkFor(string $identifier): string
    {
        $this->requestLink($identifier);
        self::assertEmailCount(1);

        return $this->linkFrom($this->onlyEmail());
    }

    private function confirm(string $link, bool $acceptCookies = true): void
    {
        if ($acceptCookies) {
            $this->acceptEssentialCookiesOnly($this->client);
        }

        $crawler = $this->client->request(Request::METHOD_GET, $link);
        $this->client->submit($crawler->selectButton('Sign in')->form());
    }

    private function onlyEmail(): Email
    {
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    private function linkFrom(Email $email): string
    {
        self::assertSame(1, preg_match('#href="(https?://[^"]+/login/link/check\?[^"]+)"#', (string) $email->getHtmlBody(), $match));

        return html_entity_decode($match[1]);
    }
}
