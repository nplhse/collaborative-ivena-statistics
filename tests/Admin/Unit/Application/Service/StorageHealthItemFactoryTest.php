<?php

declare(strict_types=1);

namespace App\Tests\Admin\Unit\Application\Service;

use App\Admin\Application\Service\StorageHealthItemFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class StorageHealthItemFactoryTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function values(): iterable
    {
        yield 'ok' => ['ok', 'success', 'fas fa-hard-drive'];
        yield 'not configured' => ['not_configured', 'success', 'fas fa-hard-drive'];
        yield 'warning' => ['warning', 'warning', 'fas fa-triangle-exclamation'];
        yield 'stale' => ['stale', 'warning', 'fas fa-triangle-exclamation'];
        yield 'unavailable' => ['unavailable', 'warning', 'fas fa-triangle-exclamation'];
        yield 'critical' => ['critical', 'danger', 'fas fa-triangle-exclamation'];
        yield 'exceeded' => ['exceeded', 'danger', 'fas fa-triangle-exclamation'];
        yield 'unknown value counts as degraded' => ['something-else', 'warning', 'fas fa-triangle-exclamation'];
    }

    #[DataProvider('values')]
    public function testShowsTheHealthValueAndLinksToDiskUsage(string $value, string $severity, string $icon): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with(StorageHealthItemFactory::DISK_USAGE_ROUTE)
            ->willReturn('/admin/operations/storage');

        $item = new StorageHealthItemFactory($urlGenerator)->create($value);

        self::assertSame('storage', $item->key);
        self::assertSame('ops.health.check.storage', $item->labelKey);
        self::assertSame($value, $item->value);
        self::assertSame($severity, $item->severity);
        self::assertSame($icon, $item->icon);
        self::assertSame('/admin/operations/storage', $item->detailUrl);
    }
}
