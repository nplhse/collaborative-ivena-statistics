<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Security;

use App\User\Application\Terms\TermsVersion;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorAvailability;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorSecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\RememberMe\ResponseListener;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Gaps of the sign-in checks closed before v0.3-beta (#1689): a remember-me cookie without a session, an
 * administrator who impersonates someone, and remember-me sessions after two-factor authentication changes.
 */
#[ResetDatabase]
final class SignInGatesTest extends WebTestCase
{
    use Factories;

    private const string SECRET = 'JBSWY3DPEHPK3PXP';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->client->disableReboot();
    }

    public function testARememberMeCookieWithoutSessionIsAskedForTheTerms(): void
    {
        self::getContainer()->set(TermsVersion::class, new TermsVersion('2026-12'));
        $user = UserFactory::createOne();

        $this->useRememberMeCookieOnly($user);
        $this->client->request(Request::METHOD_GET, '/settings/account');

        self::assertResponseRedirects('/terms/accept');
    }

    public function testARememberMeCookieWithoutSessionIsSentToTheTwoFactorSetup(): void
    {
        $this->requireTwoFactorForAdmins();
        $admin = UserFactory::new()->asAdmin()->create();

        $this->useRememberMeCookieOnly($admin);
        $this->client->request(Request::METHOD_GET, '/settings/account');

        self::assertResponseRedirects('/settings/two-factor');
    }

    public function testAnAdministratorWithoutTwoFactorLeavesAnImpersonation(): void
    {
        $this->requireTwoFactorForAdmins();
        $participant = UserFactory::new()->asParticipant()->create();
        $this->client->loginUser(UserFactory::new()->asAdmin()->create());

        $this->client->request(Request::METHOD_GET, '/settings/two-factor?_switch_user='.$participant->getUserIdentifier());
        $this->client->request(Request::METHOD_GET, '/settings/account');

        self::assertResponseRedirects('/?_switch_user=_exit');
    }

    public function testSwitchingOnTwoFactorEndsRememberMeSessions(): void
    {
        $user = UserFactory::createOne();
        $this->useRememberMeCookieOnly($user);
        $this->client->request(Request::METHOD_GET, '/settings/account');
        self::assertResponseIsSuccessful();

        // totpEnabledAt is one of the remember-me signature_properties (security.yaml), so the cookie no longer matches.
        $this->enableTwoFactor($user);
        $this->client->getCookieJar()->expire('MOCKSESSID');
        $this->client->request(Request::METHOD_GET, '/settings/account');

        self::assertResponseRedirects('/login');
    }

    /** Signs the user in through a remember-me cookie only, as after an expired session. */
    private function useRememberMeCookieOnly(User $user): void
    {
        $container = self::getContainer();
        $request = Request::create('/');
        $requestStack = $container->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requestStack);
        $requestStack->push($request);
        // A private service of the main firewall; the test container keeps it reachable for tests.
        $handler = $container->get('security.authenticator.remember_me_handler.main');
        $handler->createRememberMeCookie($this->reload($user));
        $requestStack->pop();

        $cookie = $request->attributes->get(ResponseListener::COOKIE_ATTR_NAME);
        self::assertInstanceOf(\Symfony\Component\HttpFoundation\Cookie::class, $cookie);
        $this->client->getCookieJar()->set(new Cookie($cookie->getName(), (string) $cookie->getValue(), (string) $cookie->getExpiresTime(), '/'));
    }

    private function requireTwoFactorForAdmins(): void
    {
        $container = self::getContainer();
        $container->set(TwoFactorAvailability::class, new TwoFactorAvailability($container->get(TwoFactorSecretCipher::class), false, true));
    }

    private function enableTwoFactor(User $user): void
    {
        $user = $this->reload($user);
        $user->enableTotpAuthentication(
            self::getContainer()->get(TwoFactorSecretCipher::class)->encrypt(self::SECRET),
            self::SECRET,
            [],
            new \DateTimeImmutable('2026-11-26 10:00:00'),
        );
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function reload(User $user): User
    {
        $reloaded = self::getContainer()->get(EntityManagerInterface::class)->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $reloaded);

        return $reloaded;
    }
}
