<?php

declare(strict_types=1);

namespace App\Tests\Notification\Unit\UI;

use App\Notification\UI\Http\SafeTargetPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SafeTargetPathTest extends TestCase
{
    #[DataProvider('targets')]
    public function testAcceptsOnlySameSitePaths(?string $target, ?string $expected): void
    {
        self::assertSame($expected, SafeTargetPath::sanitize($target));
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function targets(): iterable
    {
        yield 'path' => ['/statistics?period=2026', '/statistics?period=2026'];
        yield 'null' => [null, null];
        yield 'empty' => ['', null];
        yield 'absolute url' => ['https://example.org/', null];
        yield 'protocol relative' => ['//example.org/', null];
        yield 'backslash trick' => ['/\\example.org', null];
        yield 'tab trick' => ["/\t/example.org", null];
        yield 'line break' => ["/notifications\r\n", null];
        yield 'relative' => ['notifications', null];
    }
}
