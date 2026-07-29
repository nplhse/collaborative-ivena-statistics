<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Infrastructure\Query\Catalog;

use App\Allocation\Domain\Enum\IndicationRawReviewStatus;
use App\Allocation\Infrastructure\Query\Catalog\CatalogIndicationNormalizationQuery;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class CatalogIndicationNormalizationQueryTest extends TestCase
{
    public function testForTargetBuildsSynonymSummaryAndWarnings(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            [
                'id' => 11,
                'public_id' => '11111111-1111-4111-8111-111111111111',
                'code' => 101,
                'name' => 'STEMI raw',
                'review_status' => IndicationRawReviewStatus::Matched->value,
                'reviewed_at' => '2026-01-02 10:00:00',
                'occurrence_count' => 5,
            ],
            [
                'id' => 12,
                'public_id' => '22222222-2222-4222-8222-222222222222',
                'code' => 102,
                'name' => 'STEMI alias',
                'review_status' => IndicationRawReviewStatus::NeedsReview->value,
                'reviewed_at' => null,
                'occurrence_count' => 1,
            ],
        ]);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/explore/indication/raw/review');

        $summary = new CatalogIndicationNormalizationQuery(
            $connection,
            $urlGenerator,
        )->forTarget(7);

        self::assertSame(2, $summary->synonymCount());
        self::assertSame(1, $summary->matchedCount());
        self::assertSame(1, $summary->openCount());
        self::assertCount(1, $summary->warnings);
        self::assertSame('needs_review_for_target', $summary->warnings[0]->id);
        self::assertSame(5, $summary->raws[0]->occurrenceCount);
    }
}
