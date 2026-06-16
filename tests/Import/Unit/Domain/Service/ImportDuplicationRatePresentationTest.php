<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Domain\Service;

use App\Import\Domain\Service\ImportDuplicationRatePresentation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImportDuplicationRatePresentationTest extends TestCase
{
    #[DataProvider('duplicationRateProvider')]
    public function testForPercent(float $percent, string $expectedColor, string $expectedIcon): void
    {
        $badge = ImportDuplicationRatePresentation::forPercent($percent);

        self::assertSame($expectedColor, $badge->color);
        self::assertSame($expectedIcon, $badge->icon);
    }

    /**
     * @return iterable<string, array{float, string, string}>
     */
    public static function duplicationRateProvider(): iterable
    {
        yield 'below low threshold' => [9.99, 'azure', 'tabler:copy'];
        yield 'at low threshold' => [10.0, 'yellow', 'tabler:circle-half'];
        yield 'below elevated threshold' => [34.99, 'yellow', 'tabler:circle-half'];
        yield 'at elevated threshold' => [35.0, 'orange', 'tabler:alert-triangle'];
        yield 'below high threshold' => [49.99, 'orange', 'tabler:alert-triangle'];
        yield 'at high threshold' => [50.0, 'red', 'tabler:alert-triangle'];
    }
}
