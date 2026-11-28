<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Infrastructure\Security\TwoFactor;

use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use App\User\Infrastructure\Security\TwoFactor\RequireTwoFactorListener;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorAvailability;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorSecretCipher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Which requests the obligation for administrators (#1357, ADR 040) redirects, refuses or leaves alone. The wiring over
 * HTTP (redirect to the setup with its message, public media files of signed-in administrators) is in
 * `TwoFactorObligationAndResetTest`; a remember-me cookie without session and the impersonation in `SignInGatesTest`.
 */
#[CoversClass(RequireTwoFactorListener::class)]
final class RequireTwoFactorListenerTest extends TestCase
{
    private const string KEY = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';

    private const string REMEMBER_ME_COOKIE = 'REMEMBERME';

    /** @var list<string> */
    private array $tokenReads = [];

    /** @return iterable<string, array{string, Request, int|string|null}> */
    public static function requests(): iterable
    {
        // signed-in account, request, expected: null = no response, 403, or the redirect target
        yield 'administrator without two-factor' => ['admin', self::request(), '/app_settings_two_factor'];
        yield 'remember-me cookie, no session yet' => ['admin', self::request(session: false, rememberMe: true), '/app_settings_two_factor'];
        yield 'form submission' => ['admin', self::request(method: Request::METHOD_POST), 403];
        yield 'background request' => ['admin', self::request(xhr: true), 403];
        yield 'administrator with two-factor' => ['admin-with-two-factor', self::request(), null];
        yield 'plain user' => ['user', self::request(), null];
        yield 'code form of the sign-in' => ['two-factor-pending', self::request(), null];
        yield 'impersonating administrator' => ['impersonating-admin', self::request(), '/app_default?_switch_user=_exit'];
        yield 'impersonated by an administrator with two-factor' => ['impersonating-admin-with-two-factor', self::request(), null];
        yield 'the setup' => ['admin', self::request(route: 'app_settings_two_factor'), null];
        yield 'the first code' => ['admin', self::request(route: 'app_settings_two_factor_enable', method: Request::METHOD_POST), null];
        yield 'the backup codes' => ['admin', self::request(route: 'app_settings_two_factor_backup_codes'), null];
        yield 'the password confirmation' => ['admin', self::request(route: 'app_confirm_password', method: Request::METHOD_POST), null];
        yield 'the terms of use' => ['admin', self::request(route: 'app_terms_accept', method: Request::METHOD_POST), null];
        yield 'logout' => ['admin', self::request(route: 'app_logout'), null];
        yield 'language switch' => ['admin', self::request(route: 'app_locale_switch'), null];
        yield 'cookie banner' => ['admin', self::request(route: 'app_cookie_banner', method: Request::METHOD_POST), null];
        yield 'cookie preferences' => ['admin', self::request(route: 'app_cookie_preferences'), null];
        yield 'saving cookie preferences' => ['admin', self::request(route: 'app_cookie_preferences_save', method: Request::METHOD_POST), null];
        yield 'internal route' => ['admin', self::request(route: '_wdt'), null];
    }

    #[DataProvider('requests')]
    public function testTheObligationDecidesByAccountRouteAndRequestType(string $account, Request $request, int|string|null $expected): void
    {
        $event = $this->event($request);

        $this->listener(true, $this->token($account))($event);

        $response = $event->getResponse();
        match (true) {
            null === $expected => self::assertNull($response),
            \is_int($expected) => self::assertSame($expected, $response?->getStatusCode()),
            default => self::assertSame($expected, $response instanceof RedirectResponse ? $response->getTargetUrl() : null),
        };
    }

    public function testTheRedirectCarriesTheMessage(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $request = self::request();
        $request->setSession($session);

        $this->listener(true, $this->token('admin'))($this->event($request));

        self::assertSame(['flash.settings.two_factor.required'], array_map(
            static fn (mixed $message): string => $message instanceof TranslatableMessage ? $message->getMessage() : '',
            $session->getFlashBag()->get('warning'),
        ));
    }

    /** @return iterable<string, array{bool, Request, int}> */
    public static function requestsThatDoNotReadTheToken(): iterable
    {
        // obligation on, request, request type
        yield 'obligation off' => [false, self::request(), HttpKernelInterface::MAIN_REQUEST];
        // Reading the token would start a session and turn public responses (media files) private.
        yield 'no session and no remember-me cookie' => [true, self::request(session: false), HttpKernelInterface::MAIN_REQUEST];
        yield 'stateless route' => [true, self::request(stateless: true), HttpKernelInterface::MAIN_REQUEST];
        yield 'sub-request' => [true, self::request(), HttpKernelInterface::SUB_REQUEST];
    }

    #[DataProvider('requestsThatDoNotReadTheToken')]
    public function testSomeRequestsAreLeftAloneWithoutReadingTheToken(bool $required, Request $request, int $type): void
    {
        $event = $this->event($request, $type);

        $this->listener($required, $this->token('admin'))($event);

        self::assertNull($event->getResponse());
        self::assertSame([], $this->tokenReads);
    }

    private static function request(
        string $route = 'admin',
        string $method = Request::METHOD_GET,
        bool $xhr = false,
        bool $session = true,
        bool $rememberMe = false,
        bool $stateless = false,
    ): Request {
        $request = Request::create('/page', $method);
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

    private function listener(bool $required, ?TokenInterface $token): RequireTwoFactorListener
    {
        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturnCallback(function () use ($token): ?TokenInterface {
            $this->tokenReads[] = 'read';

            return $token;
        });

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $name, array $parameters = []): string => '/'.$name.([] === $parameters ? '' : '?'.http_build_query($parameters)),
        );

        return new RequireTwoFactorListener(
            $tokenStorage,
            new TwoFactorAvailability(new TwoFactorSecretCipher(self::KEY), false, $required),
            $urlGenerator,
            self::REMEMBER_ME_COOKIE,
        );
    }

    private function token(string $account): TokenInterface
    {
        return match ($account) {
            'admin' => $this->passwordToken($this->user('alice', true, false)),
            'admin-with-two-factor' => $this->passwordToken($this->user('alice', true, true)),
            'user' => $this->passwordToken($this->user('bob', false, false)),
            'two-factor-pending' => new TwoFactorToken($this->passwordToken($this->user('alice', true, false)), null, 'main', ['totp']),
            'impersonating-admin' => $this->switchUserToken($this->user('alice', true, false)),
            'impersonating-admin-with-two-factor' => $this->switchUserToken($this->user('alice', true, true)),
            default => throw new \InvalidArgumentException($account),
        };
    }

    private function passwordToken(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    /** The administrator impersonates Bob, a plain user. */
    private function switchUserToken(User $admin): SwitchUserToken
    {
        $bob = $this->user('bob', false, false);

        return new SwitchUserToken($bob, 'main', $bob->getRoles(), $this->passwordToken($admin));
    }

    private function user(string $username, bool $admin, bool $twoFactor): User
    {
        $user = new User()->setUsername($username)->setRoles($admin ? [UserRole::ADMIN] : []);
        if ($twoFactor) {
            $user->enableTotpAuthentication('v1:encrypted', 'JBSWY3DPEHPK3PXP', [], new \DateTimeImmutable('2026-11-22 12:00:00'));
        }

        return $user;
    }
}
