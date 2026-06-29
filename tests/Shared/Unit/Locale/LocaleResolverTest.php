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
