<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\DataQuality;

use App\Statistics\DataQuality\DataQualityLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataQualityLevelTest extends TestCase
{
    #[DataProvider('badgeProvider')]
    public function testBadgeVariantAndClass(DataQualityLevel $level, string $variant, string $badgeClass): void
    {
        self::assertSame($variant, $level->badgeVariant());
        self::assertSame($badgeClass, $level->badgeClass());
    }

    /**
     * @return iterable<string, array{0: DataQualityLevel, 1: string, 2: string}>
     */
    public static function badgeProvider(): iterable
    {
        yield 'low' => [DataQualityLevel::Low, 'red', 'bg-red-lt'];
        yield 'medium' => [DataQualityLevel::Medium, 'yellow', 'bg-yellow-lt'];
        yield 'high' => [DataQualityLevel::High, 'green', 'bg-green-lt'];
    }
}
