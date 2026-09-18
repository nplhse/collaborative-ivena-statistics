<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Explore\Catalog;

use App\Allocation\Application\Explore\Catalog\CatalogAllocationYearUrlFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class CatalogAllocationYearUrlFactoryTest extends TestCase
{
    public function testBuildsAllocationListUrlForCalendarYear(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with(
                'app_explore_allocation_list',
                [
                    'occasion' => 12,
                    'createdFrom' => '2024-01-01',
                    'createdUntil' => '2024-12-31',
                ],
            )
            ->willReturn('/explore/allocation?occasion=12&createdFrom=2024-01-01&createdUntil=2024-12-31');

        $factory = new CatalogAllocationYearUrlFactory($urlGenerator);

        self::assertSame(
            '/explore/allocation?occasion=12&createdFrom=2024-01-01&createdUntil=2024-12-31',
            $factory->forYear(['occasion' => 12], 2024),
        );
    }

    public function testMapsYearsWithCountsAndSkipsEmptyYears(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::exactly(2))
            ->method('generate')
            ->willReturnCallback(static fn (string $route, array $params = []): string => match (true) {
                'app_explore_allocation_list' === $route
                    && ($params['indication'] ?? null) === 101
                    && '2023-01-01' === ($params['createdFrom'] ?? null)
                    && '2023-12-31' === ($params['createdUntil'] ?? null) => '/explore/allocation?y=2023',
                'app_explore_allocation_list' === $route
                    && ($params['indication'] ?? null) === 101
                    && '2024-01-01' === ($params['createdFrom'] ?? null)
                    && '2024-12-31' === ($params['createdUntil'] ?? null) => '/explore/allocation?y=2024',
                default => throw new \InvalidArgumentException($route),
            });

        $factory = new CatalogAllocationYearUrlFactory($urlGenerator);
        $urls = $factory->forYears(
            ['indication' => 101],
            [
                ['year' => 2022, 'count' => 0],
                ['year' => 2023, 'count' => 4],
                ['year' => 2024, 'count' => 12],
            ],
        );

        self::assertSame([
            2023 => '/explore/allocation?y=2023',
            2024 => '/explore/allocation?y=2024',
        ], $urls);
    }

    public function testHospitalYearUsesHospitalFilter(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with(
                'app_explore_allocation_list',
                [
                    'hospitalFilter' => '42',
                    'createdFrom' => '2025-01-01',
                    'createdUntil' => '2025-12-31',
                ],
            )
            ->willReturn('/explore/allocation?hospitalFilter=42');

        $factory = new CatalogAllocationYearUrlFactory($urlGenerator);

        self::assertSame(
            [2025 => '/explore/allocation?hospitalFilter=42'],
            $factory->forYears(['hospitalFilter' => '42'], [['year' => 2025, 'count' => 8]]),
        );
    }
}
