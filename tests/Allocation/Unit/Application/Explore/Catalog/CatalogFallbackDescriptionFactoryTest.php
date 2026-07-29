<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Explore\Catalog;

use App\Allocation\Application\DTO\CatalogCoverage;
use App\Allocation\Application\Explore\Catalog\CatalogFallbackDescriptionFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CatalogFallbackDescriptionFactoryTest extends TestCase
{
    public function testEmptyCoverageUsesEmptyFallbackKey(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('catalog.fallback.empty', ['name' => 'Test'], 'allocation')
            ->willReturn('empty-fallback');

        $factory = new CatalogFallbackDescriptionFactory($translator);

        self::assertSame('empty-fallback', $factory->create('Test', CatalogCoverage::empty()));
    }

    public function testSuppressedCoverageUsesSuppressedFallbackKey(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('catalog.fallback.suppressed', ['name' => 'Rare'], 'allocation')
            ->willReturn('suppressed-fallback');

        $factory = new CatalogFallbackDescriptionFactory($translator);
        $coverage = new CatalogCoverage(2, 100, 0, 0, 0, null, null, [], true);

        self::assertSame('suppressed-fallback', $factory->create('Rare', $coverage));
    }

    public function testCoverageWithPeriodUsesPeriodFallbackKey(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with(
                'catalog.fallback.with_period',
                self::callback(static fn (array $params): bool => 'Indication A' === $params['name']
                    && 12 === $params['allocations']
                    && 3 === $params['hospitals']
                    && '2020' === $params['from']
                    && '2024' === $params['to']),
                'allocation',
            )
            ->willReturn('period-fallback');

        $factory = new CatalogFallbackDescriptionFactory($translator);
        $coverage = new CatalogCoverage(
            12,
            100,
            3,
            2,
            1,
            new \DateTimeImmutable('2020-01-01'),
            new \DateTimeImmutable('2024-12-31'),
            [['year' => 2020, 'count' => 12]],
            false,
        );

        self::assertSame('period-fallback', $factory->create('Indication A', $coverage));
    }
}
