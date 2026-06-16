<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\UI\Twig;

use App\Import\UI\Twig\ImportTwigExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImportTwigExtensionTest extends TestCase
{
    private ImportTwigExtension $extension;

    #[\Override]
    protected function setUp(): void
    {
        $this->extension = new ImportTwigExtension();
    }

    #[DataProvider('rejectionBadgeProvider')]
    public function testImportRateBadgeForRejection(float $percent, string $expectedColor, string $expectedIcon): void
    {
        $badge = $this->extension->importRateBadge($percent, 'rejection');

        self::assertSame($expectedColor, $badge['color']);
        self::assertSame($expectedIcon, $badge['icon']);
    }

    #[DataProvider('duplicationBadgeProvider')]
    public function testImportRateBadgeForDuplication(float $percent, string $expectedColor, string $expectedIcon): void
    {
        $badge = $this->extension->importRateBadge($percent, 'duplication');

        self::assertSame($expectedColor, $badge['color']);
        self::assertSame($expectedIcon, $badge['icon']);
    }

    public function testImportRateBadgeRejectsUnknownKind(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown import rate kind "unknown".');

        $this->extension->importRateBadge(12.5, 'unknown');
    }

    /**
     * @return iterable<string, array{float, string, string}>
     */
    public static function rejectionBadgeProvider(): iterable
    {
        yield 'completed tier' => [4.99, 'green', 'tabler:circle-check'];
        yield 'partial tier' => [20.0, 'yellow', 'tabler:circle-half'];
        yield 'failed tier' => [35.0, 'red', 'tabler:alert-triangle'];
    }

    /**
     * @return iterable<string, array{float, string, string}>
     */
    public static function duplicationBadgeProvider(): iterable
    {
        yield 'low tier' => [5.0, 'azure', 'tabler:copy'];
        yield 'elevated tier' => [20.0, 'yellow', 'tabler:circle-half'];
        yield 'high tier' => [40.0, 'orange', 'tabler:alert-triangle'];
        yield 'critical tier' => [50.0, 'red', 'tabler:alert-triangle'];
    }
}
