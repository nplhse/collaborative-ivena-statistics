<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\Security\Terms;

use App\Shared\Application\Navigation\Contract\NavigationPageLinksProviderInterface;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Application\Terms\TermsVersion;
use App\User\Domain\Entity\TermsAcceptance;
use App\User\Domain\Entity\User;
use App\User\Domain\Enum\TermsAcceptanceKind;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Repository\TermsAcceptanceRepository;
use App\User\Infrastructure\Security\Terms\RequireTermsAcceptanceListener;
use App\User\Infrastructure\Security\Terms\TermsAcceptanceStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorToken;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Which requests the terms of use (#1564, ADR 043) redirect, refuse or leave alone. An integration test because
 * `TermsAcceptanceStatus` asks the database. Alice has not accepted version 2026-12, Bob has. The main flow over HTTP
 * (accept and return, `/terms` and logout reachable, terms before the two-factor setup) is in
 * `TermsAcceptanceAtLoginTest`; a remember-me cookie without session in `SignInGatesTest`.
 */
final class RequireTermsAcceptanceListenerTest extends DatabaseKernelTestCase
{
    private const string REMEMBER_ME_COOKIE = 'REMEMBERME';

    /** @var list<string> */
    private array $tokenReads = [];

    /** @return iterable<string, array{string, Request, int|string|null}> */
    public static function requests(): iterable
    {
        // signed-in account, request, expected: null = no response, 403, or the redirect target
        yield 'without acceptance' => ['alice', self::request(), '/app_terms_accept'];
        yield 'remember-me cookie, no session yet' => ['alice', self::request(session: false, rememberMe: true), '/app_terms_accept'];
        yield 'form submission' => ['alice', self::request(method: Request::METHOD_POST), 403];
        yield 'background request' => ['alice', self::request(xhr: true), 403];
        yield 'with acceptance' => ['bob', self::request(), null];
        yield 'impersonating administrator' => ['impersonating-admin', self::request(), null];
        yield 'code form of the sign-in' => ['two-factor-pending', self::request(), null];
        yield 'the acceptance page' => ['alice', self::request(route: 'app_terms_accept', method: Request::METHOD_POST), null];
        yield 'logout' => ['alice', self::request(route: 'app_logout'), null];
        yield 'language switch' => ['alice', self::request(route: 'app_locale_switch'), null];
        yield 'cookie banner' => ['alice', self::request(route: 'app_cookie_banner', method: Request::METHOD_POST), null];
        yield 'cookie preferences' => ['alice', self::request(route: 'app_cookie_preferences'), null];
        yield 'saving cookie preferences' => ['alice', self::request(route: 'app_cookie_preferences_save', method: Request::METHOD_POST), null];
        yield 'internal route' => ['alice', self::request(route: '_wdt'), null];
        yield 'terms page' => ['alice', self::request(path: '/terms', route: 'app_page_show'), null];
        yield 'imprint' => ['alice', self::request(path: '/imprint', route: 'app_page_show'), null];
        yield 'another CMS page' => ['alice', self::request(path: '/about-us', route: 'app_page_show'), '/app_terms_accept'];
    }

    #[DataProvider('requests')]
    public function testTheTermsDecideByAccountRouteAndRequestType(string $account, Request $request, int|string|null $expected): void
    {
        $event = $this->event($request);

        $this->listener('2026-12', $this->token($account))($event);

        $response = $event->getResponse();
        match (true) {
            null === $expected => self::assertNull($response),
            \is_int($expected) => self::assertSame($expected, $response?->getStatusCode()),
            default => self::assertSame($expected, $response instanceof RedirectResponse ? $response->getTargetUrl() : null),
        };
    }

    public function testTheRequestedPageIsKeptForTheReturn(): void
    {
        $request = self::request(path: '/settings/account?tab=1');

        $this->listener('2026-12', $this->token('alice'))($this->event($request));

        self::assertSame('/settings/account?tab=1', $request->getSession()->get(RequireTermsAcceptanceListener::TARGET_PATH_KEY));
    }

    /** @return iterable<string, array{?string, Request, int}> */
    public static function requestsThatDoNotReadTheToken(): iterable
    {
        // configured version, request, request type
        yield 'no configured version' => [null, self::request(), HttpKernelInterface::MAIN_REQUEST];
        // Reading the token would start a session and turn public responses (media files) private.
        yield 'no session and no remember-me cookie' => ['2026-12', self::request(session: false), HttpKernelInterface::MAIN_REQUEST];
        yield 'stateless route' => ['2026-12', self::request(stateless: true), HttpKernelInterface::MAIN_REQUEST];
        yield 'sub-request' => ['2026-12', self::request(), HttpKernelInterface::SUB_REQUEST];
    }

    #[DataProvider('requestsThatDoNotReadTheToken')]
    public function testSomeRequestsAreLeftAloneWithoutReadingTheToken(?string $version, Request $request, int $type): void
    {
        $event = $this->event($request, $type);

        $this->listener($version, $this->token('alice'))($event);

        self::assertNull($event->getResponse());
        self::assertSame([], $this->tokenReads);
    }

    private static function request(
        string $path = '/settings/account',
        string $route = 'app_settings_account',
        string $method = Request::METHOD_GET,
        bool $xhr = false,
        bool $session = true,
        bool $rememberMe = false,
        bool $stateless = false,
    ): Request {
        $request = Request::create($path, $method);
        $request->attributes->set('_route', $route);
        $request->setSession($storage = new Session(new MockArraySessionStorage()));
        if ($session) {
            // A session cookie from an earlier request: hasPreviousSession().
            $request->cookies->set($storage->getName(), 'session-id');
        }

        if ($rememberMe) {
            $request->cookies->set(self::REMEMBER_ME_COOKIE, 'remembered');
        }

        if ($xhr) {
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        }

        if ($stateless) {
            $request->attributes->set('_stateless', true);
        }

        return $request;
    }

    private function event(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);
    }

    private function listener(?string $version, TokenInterface $token): RequireTermsAcceptanceListener
    {
        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturnCallback(function () use ($token): TokenInterface {
            $this->tokenReads[] = 'read';

            return $token;
        });

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(static fn (string $name): string => '/'.$name);
        // The links of the legal pages (terms, imprint, privacy) as the CMS resolves them.
        $pageLinks = $this->createStub(NavigationPageLinksProviderInterface::class);
        $pageLinks->method('linksForKeys')->willReturn([['url' => '/terms', 'label' => 'Terms of use'], ['url' => '/imprint', 'label' => 'Imprint']]);

        return new RequireTermsAcceptanceListener(
            $tokenStorage,
            new TermsAcceptanceStatus(new TermsVersion($version), self::getContainer()->get(TermsAcceptanceRepository::class)),
            $urlGenerator,
            $pageLinks,
            self::REMEMBER_ME_COOKIE,
        );
    }

    private function token(string $account): TokenInterface
    {
        return match ($account) {
            'alice' => $this->passwordToken($this->user('alice')),
            'bob' => $this->passwordToken($this->acceptedBy($this->user('bob'))),
            'two-factor-pending' => new TwoFactorToken($this->passwordToken($this->user('alice')), null, 'main', ['totp']),
            'impersonating-admin' => $this->impersonationToken(),
            default => throw new \InvalidArgumentException($account),
        };
    }

    /** An administrator who accepted impersonates Alice, who has not. */
    private function impersonationToken(): SwitchUserToken
    {
        $admin = $this->acceptedBy(UserFactory::new()->asAdmin()->create(['username' => 'admin']));
        $alice = $this->user('alice');

        return new SwitchUserToken($alice, 'main', $alice->getRoles(), $this->passwordToken($admin));
    }

    private function passwordToken(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    private function user(string $username): User
    {
        return UserFactory::createOne(['username' => $username, 'email' => $username.'@example.test']);
    }

    private function acceptedBy(User $user): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new TermsAcceptance($user, TermsAcceptanceKind::Terms, '2026-12', 'en', null));
        $entityManager->flush();

        return $user;
    }
}
