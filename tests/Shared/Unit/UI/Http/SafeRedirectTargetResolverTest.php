<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\UI\Http;

use App\Shared\UI\Http\SafeRedirectTargetResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class SafeRedirectTargetResolverTest extends TestCase
{
    private SafeRedirectTargetResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new SafeRedirectTargetResolver();
    }

    #[DataProvider('isSafeProvider')]
    public function testIsSafe(string $target, bool $expected): void
    {
        $request = Request::create('https://app.example/current');

        self::assertSame($expected, $this->resolver->isSafe($request, $target));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function isSafeProvider(): iterable
    {
        yield 'local path' => ['/statistics/analysis/library', true];
        yield 'local path with query' => ['/explore?x=1', true];
        yield 'protocol relative' => ['//evil.example/phish', false];
        yield 'external absolute' => ['https://evil.example/phish', false];
        yield 'same host absolute' => ['https://app.example/statistics/analysis/library', true];
        yield 'same host root' => ['https://app.example', true];
        yield 'empty' => ['', false];
    }

    public function testResolveReturnsCandidateWhenSafe(): void
    {
        $request = Request::create('https://app.example/current');

        self::assertSame(
            '/statistics/analysis/library',
            $this->resolver->resolve('/statistics/analysis/library', $request, '/fallback'),
        );
    }

    public function testResolveReturnsFallbackWhenUnsafeOrEmpty(): void
    {
        $request = Request::create('https://app.example/current');

        self::assertSame('/fallback', $this->resolver->resolve('https://evil.example/x', $request, '/fallback'));
        self::assertSame('/fallback', $this->resolver->resolve(null, $request, '/fallback'));
        self::assertSame('/fallback', $this->resolver->resolve('', $request, '/fallback'));
        self::assertSame('/fallback', $this->resolver->resolve('//evil.example', $request, '/fallback'));
    }
}
