<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Twig\Components;

use App\Shared\UI\Twig\Components\Badge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BadgeTest extends TestCase
{
    #[DataProvider('variantProvider')]
    public function testVariantMapping(string $variant, string $expectedVariant): void
    {
        $badge = new Badge();
        $badge->variant = $variant;

        self::assertSame($expectedVariant, $badge->getVariant());
        self::assertSame(
            \sprintf('badge bg-%s-lt text-%s-lt-fg', $expectedVariant, $expectedVariant),
            $badge->getCssClass(),
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function variantProvider(): iterable
    {
        yield 'secondary' => ['secondary', 'secondary'];
        yield 'green' => ['green', 'green'];
        yield 'red' => ['red', 'red'];
        yield 'yellow' => ['yellow', 'yellow'];
        yield 'azure' => ['azure', 'azure'];
        yield 'purple' => ['purple', 'purple'];
        yield 'blue' => ['blue', 'blue'];
        yield 'orange' => ['orange', 'orange'];
        yield 'unknown fallback' => ['notice', 'secondary'];
        yield 'empty fallback' => ['', 'secondary'];
        yield 'uppercase' => ['GREEN', 'green'];
    }

    public function testDefaultVariantIsSecondary(): void
    {
        $badge = new Badge();

        self::assertSame('secondary', $badge->variant);
        self::assertSame('secondary', $badge->getVariant());
        self::assertSame('badge bg-secondary-lt text-secondary-lt-fg', $badge->getCssClass());
    }
}
