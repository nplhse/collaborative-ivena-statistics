<?php

declare(strict_types=1);

namespace App\Tests\Admin\Unit\UI\Twig;

use App\Admin\UI\Twig\AdminTwigExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminTwigExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function sizes(): iterable
    {
        yield 'bytes' => [512, '512 B'];
        yield 'kilobytes' => [1536, '1.5 KB'];
        yield 'gigabytes' => [3 * 1024 ** 3, '3.00 GB'];
    }

    #[DataProvider('sizes')]
    public function testFormatsBytesLikeTheStorageContext(int $bytes, string $expected): void
    {
        self::assertSame($expected, new AdminTwigExtension()->formatBytes($bytes));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function deltas(): iterable
    {
        yield 'growth' => [1536, '+1.5 KB'];
        yield 'shrink' => [-300, '−300 B'];
        yield 'unchanged' => [0, '±0 B'];
    }

    #[DataProvider('deltas')]
    public function testFormatsSizeChangesWithTheirSign(int $bytes, string $expected): void
    {
        self::assertSame($expected, new AdminTwigExtension()->formatBytesDelta($bytes));
    }
}
