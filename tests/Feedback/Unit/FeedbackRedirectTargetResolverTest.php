<?php

declare(strict_types=1);

namespace App\Tests\Feedback\Unit;

use App\Feedback\UI\Http\FeedbackRedirectTargetResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FeedbackRedirectTargetResolverTest extends TestCase
{
    private FeedbackRedirectTargetResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new FeedbackRedirectTargetResolver();
    }

    #[DataProvider('safeTargetProvider')]
    public function testResolvesSafeLocalTargets(string $input, string $expected): void
    {
        self::assertSame($expected, $this->resolver->resolve($input));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function safeTargetProvider(): iterable
    {
        yield 'root' => ['/', '/'];
        yield 'path with query' => ['/explore/hospital?search=clinic', '/explore/hospital?search=clinic'];
        yield 'strips fragment' => ['/explore/hospital?search=clinic#filters', '/explore/hospital?search=clinic'];
        yield 'empty falls back' => ['', '/'];
        yield 'external url falls back' => ['//evil.example/phish', '/'];
        yield 'absolute url falls back' => ['https://evil.example/phish', '/'];
    }
}
