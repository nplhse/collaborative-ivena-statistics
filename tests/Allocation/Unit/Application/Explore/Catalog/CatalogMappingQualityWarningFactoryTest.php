<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Explore\Catalog;

use App\Allocation\Application\DTO\CatalogQualityWarning;
use App\Allocation\Application\Explore\Catalog\CatalogMappingQualityWarningFactory;
use App\Allocation\Infrastructure\Query\IndicationRawReviewHealthCheckQuery;
use App\Allocation\Infrastructure\Query\IndicationRawReviewHealthCheckResult;
use App\Allocation\Infrastructure\Query\IndicationRawReviewHealthCheckSeverity;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class CatalogMappingQualityWarningFactoryTest extends TestCase
{
    public function testCreateKeepsOnlyNonZeroFailAndWarnResults(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(static function (string $sql): int {
            if (str_contains($sql, "review_status <> 'matched'")) {
                return 2;
            }
            if (str_contains($sql, 'normalized_id IS NOT NULL') && str_contains($sql, 'target_id IS NULL')) {
                return 1;
            }

            return 0;
        });

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')
            ->willReturn('/explore/indication/raw/review');

        $warnings = new CatalogMappingQualityWarningFactory(
            new IndicationRawReviewHealthCheckQuery($connection),
            $urlGenerator,
        )->create();

        self::assertGreaterThanOrEqual(2, \count($warnings));
        $ids = array_map(static fn (CatalogQualityWarning $warning): string => $warning->id, $warnings);
        self::assertContains('target_not_matched', $ids);
        self::assertContains('normalized_without_target', $ids);

        $fail = array_values(array_filter(
            $warnings,
            static fn (CatalogQualityWarning $warning): bool => 'target_not_matched' === $warning->id,
        ))[0];
        self::assertTrue($fail->isFail());
        self::assertSame(2, $fail->count);
    }

    public function testQualityWarningSeverityHelpers(): void
    {
        $fail = new CatalogQualityWarning(
            'x',
            'label',
            1,
            IndicationRawReviewHealthCheckSeverity::Fail,
        );
        $warn = new CatalogQualityWarning(
            'y',
            'label',
            1,
            IndicationRawReviewHealthCheckSeverity::Warn,
        );

        self::assertTrue($fail->isFail());
        self::assertFalse($warn->isFail());
        self::assertSame('FAIL', new IndicationRawReviewHealthCheckResult(
            'x',
            'label',
            1,
            IndicationRawReviewHealthCheckSeverity::Fail,
        )->statusLabel());
    }
}
