<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Domain\Service;

use App\Import\Domain\Service\ImportRejectionRatePresentation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImportRejectionRatePresentationTest extends TestCase
{
    #[DataProvider('rejectionRateProvider')]
    public function testForPercent(float $percent, string $expectedColor, string $expectedIcon): void
    {
        $badge = ImportRejectionRatePresentation::forPercent($percent);

        self::assertSame($expectedColor, $badge->color);
        self::assertSame($expectedIcon, $badge->icon);
    }

    /**
     * @return iterable<string, array{float, string, string}>
     */
    public static function rejectionRateProvider(): iterable
    {
        yield 'below complete threshold' => [4.99, 'green', 'tabler:circle-check'];
        yield 'at complete threshold' => [5.0, 'yellow', 'tabler:circle-half'];
        yield 'below failed threshold' => [34.99, 'yellow', 'tabler:circle-half'];
        yield 'at failed threshold' => [35.0, 'red', 'tabler:alert-triangle'];
    }
}
