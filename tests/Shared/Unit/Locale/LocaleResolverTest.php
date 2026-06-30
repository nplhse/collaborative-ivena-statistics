<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Locale;

use App\Shared\Application\Locale\LocaleResolver;
use App\Shared\Infrastructure\Locale\LocaleCookieManager;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class LocaleResolverTest extends TestCase
{
    private LocaleResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new LocaleResolver(new LocaleCookieManager());
    }

    public function testUserLocaleTakesPriorityOverCookieAndAcceptLanguage(): void
    {
        $user = $this->createUserWithLocale('de');
        $request = Request::create('/', Request::METHOD_GET, cookies: [LocaleCookieManager::COOKIE_NAME => 'en']);
        $request->headers->set('Accept-Language', 'fr-FR');

        self::assertSame('de', $this->resolver->resolve($request, $user));
    }

    public function testCookieLocaleUsedWhenUserHasNoExplicitLocale(): void
    {
        $user = $this->createUserWithLocale(null);
        $request = Request::create('/', Request::METHOD_GET, cookies: [LocaleCookieManager::COOKIE_NAME => 'de']);
        $request->headers->set('Accept-Language', 'en-US');

        self::assertSame('de', $this->resolver->resolve($request, $user));
    }

    #[DataProvider('acceptLanguageProvider')]
    public function testAcceptLanguageResolution(?string $acceptLanguage, string $expected): void
    {
        $request = Request::create('/');
        if (null !== $acceptLanguage) {
            $request->headers->set('Accept-Language', $acceptLanguage);
        }

        self::assertSame($expected, $this->resolver->resolve($request, null));
    }

    /**
     * @return iterable<string, array{0: ?string, 1: string}>
     */
    public static function acceptLanguageProvider(): iterable
    {
        yield 'german browser' => ['de-DE,de;q=0.9', 'de'];
        yield 'german prefix' => ['de', 'de'];
        yield 'french browser' => ['fr-FR,fr;q=0.9', 'en'];
        yield 'missing header' => [null, 'en'];
    }

    public function testNullUserLocaleAndInvalidCookieFallsBackToAcceptLanguage(): void
    {
        $request = Request::create('/', Request::METHOD_GET, cookies: [LocaleCookieManager::COOKIE_NAME => 'invalid']);
        $request->headers->set('Accept-Language', 'de-DE');

        self::assertSame('de', $this->resolver->resolve($request, null));
    }

    public function testResolveAutomaticDefaultUsesCookieBeforeAcceptLanguage(): void
    {
        $request = Request::create('/', Request::METHOD_GET, cookies: [LocaleCookieManager::COOKIE_NAME => 'de']);
        $request->headers->set('Accept-Language', 'en-US');

        self::assertSame('de', $this->resolver->resolveAutomaticDefault($request));
    }

    public function testResolveAutomaticDefaultIgnoresUserLocale(): void
    {
        $user = $this->createUserWithLocale('en');
        $request = Request::create('/');
        $request->headers->set('Accept-Language', 'de-DE');

        self::assertSame('de', $this->resolver->resolveAutomaticDefault($request));
        self::assertSame('en', $this->resolver->resolve($request, $user));
    }

    public function testResolveForUserReturnsExplicitLocale(): void
    {
        self::assertSame('de', $this->resolver->resolveForUser($this->createUserWithLocale('de')));
    }

    public function testResolveForUserFallsBackToDefaultWhenLocaleNotExplicit(): void
    {
        self::assertSame('en', $this->resolver->resolveForUser($this->createUserWithLocale(null)));
    }

    public function testResolveForUserFallsBackToDefaultForNullUser(): void
    {
        self::assertSame('en', $this->resolver->resolveForUser(null));
    }

    public function testGroupEmailsByLocaleGroupsUsersByExplicitLocale(): void
    {
        $deUser = $this->createUserWithLocale('de');
        $deUser->setEmail('de-admin@example.test');
        $enUser = $this->createUserWithLocale('en');
        $enUser->setEmail('en-admin@example.test');
        $defaultUser = $this->createUserWithLocale(null);
        $defaultUser->setEmail('default-admin@example.test');

        $grouped = $this->resolver->groupEmailsByLocale([$deUser, $enUser, $defaultUser]);

        self::assertSame(['de-admin@example.test'], $grouped['de']);
        self::assertSame(['en-admin@example.test', 'default-admin@example.test'], $grouped['en']);
    }

    public function testGroupEmailsByLocaleDeduplicatesEmailsWithinLocale(): void
    {
        $userA = $this->createUserWithLocale('de');
        $userA->setEmail('DUPLICATE@example.test');
        $userB = $this->createUserWithLocale('de');
        $userB->setEmail('duplicate@example.test');

        $grouped = $this->resolver->groupEmailsByLocale([$userA, $userB]);

        self::assertSame(['duplicate@example.test'], $grouped['de']);
    }

    public function testGroupEmailsByLocaleSkipsUsersWithoutEmail(): void
    {
        $user = new User();
        $user->setUsername('no-email-user');
        $user->setPassword('hashed');
        $user->setLocale('de');

        self::assertSame([], $this->resolver->groupEmailsByLocale([$user]));
    }

    private function createUserWithLocale(?string $locale): User
    {
        $user = new User();
        $user->setUsername('locale-user');
        $user->setEmail('locale-user@example.test');
        $user->setPassword('hashed');
        $user->setLocale($locale);

        return $user;
    }
}
