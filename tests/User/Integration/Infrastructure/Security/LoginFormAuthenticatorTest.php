<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\Security;

use App\Shared\Domain\Entity\CookieConsent;
use App\Shared\Infrastructure\Consent\CookieConsentService;
use App\Shared\Infrastructure\Repository\CookieConsentRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Security\LoginFormAuthenticator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Which account the login form asks for (#1355): the user badge decides the account and the login throttling key
 * (IP + identifier), so a username and the email of the same account count against the same limit. The sign-in over
 * HTTP and the throttling itself: SecurityControllerTest.
 */
final class LoginFormAuthenticatorTest extends DatabaseKernelTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function identifiers(): iterable
    {
        yield 'the email in any case, with spaces around it' => ['  Alice@Example.TEST ', 'alice'];
        yield 'the username with spaces around it' => ['  alice  ', 'alice'];
        yield 'an unknown email stays as entered' => ['nobody@example.test', 'nobody@example.test'];
        yield 'an old username with an inner space' => ['John Doe', 'John Doe'];
    }

    #[DataProvider('identifiers')]
    public function testTheBadgeNamesTheAccountOfTheIdentifier(string $entered, string $expected): void
    {
        self::bootKernel();
        UserFactory::createOne(['username' => 'alice', 'email' => 'alice@example.test']);
        $request = $this->loginRequest($entered);

        $passport = self::getContainer()->get(LoginFormAuthenticator::class)->authenticate($request);

        self::assertSame($expected, $passport->getBadge(UserBadge::class)?->getUserIdentifier());
    }

    /** A POST of the login form by a visitor who has decided on cookies (the authenticator refuses others). */
    private function loginRequest(string $identifier): Request
    {
        $container = self::getContainer();
        $consent = new CookieConsent('subject-1')->setConsents(false);
        $container->get(CookieConsentRepository::class)->save($consent);

        $request = Request::create('/login', Request::METHOD_POST, ['login' => ['username' => $identifier, 'password' => 'password']]);
        $request->cookies->set($container->get(CookieConsentService::class)->subjectCookieName(), 'subject-1');
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }
}
