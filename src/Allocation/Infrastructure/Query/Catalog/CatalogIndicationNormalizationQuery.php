<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Query\Catalog;

use App\Allocation\Application\DTO\CatalogMappedRawTerm;
use App\Allocation\Application\DTO\CatalogNormalizationSummary;
use App\Allocation\Application\DTO\CatalogQualityWarning;
use App\Allocation\Domain\Enum\IndicationRawReviewStatus;
use App\Allocation\Infrastructure\Query\IndicationRawReviewHealthCheckSeverity;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Loads mapped raw indications (synonyms) and local quality signals for a normalized target.
 */
final readonly class CatalogIndicationNormalizationQuery
{
    public function __construct(
        private Connection $connection,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function forTarget(int $normalizedId): CatalogNormalizationSummary
    {
        try {
            $rows = $this->fetchRawRows($normalizedId);
        } catch (Exception) {
            return new CatalogNormalizationSummary([], [], []);
        }

        $statusCounts = [];
        $raws = [];
        foreach ($rows as $row) {
            $status = IndicationRawReviewStatus::tryFrom($row['review_status'])
                ?? IndicationRawReviewStatus::Unreviewed;
            $statusCounts[$status->value] = ($statusCounts[$status->value] ?? 0) + 1;

            $reviewedAt = $this->parseDateTime($row['reviewed_at']);

            $raws[] = new CatalogMappedRawTerm(
                id: (int) $row['id'],
                publicId: (string) $row['public_id'],
                code: (int) $row['code'],
                name: $row['name'],
                reviewStatus: $status,
                occurrenceCount: (int) $row['occurrence_count'],
                reviewedAt: $reviewedAt,
            );
        }

        return new CatalogNormalizationSummary(
            raws: $raws,
            statusCounts: $statusCounts,
            warnings: $this->buildWarnings($statusCounts),
        );
    }

    /**
     * @return list<array{
     *     id: int|string,
     *     public_id: string|null,
     *     code: int|string,
     *     name: string,
     *     review_status: string,
     *     reviewed_at: string|\DateTimeInterface|null,
     *     occurrence_count: int|string
     * }>
     */
    private function fetchRawRows(int $normalizedId): array
    {
        /** @var list<array{
         *     id: int|string,
         *     public_id: string|null,
         *     code: int|string,
         *     name: string,
         *     review_status: string,
         *     reviewed_at: string|\DateTimeInterface|null,
         *     occurrence_count: int|string
         * }> $rows
         */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT r.id,
       r.public_id,
       r.code,
       r.name,
       r.review_status,
       r.reviewed_at,
       COALESCE(p.cnt, 0) + COALESCE(s.cnt, 0) AS occurrence_count
FROM indication_raw r
LEFT JOIN (
    SELECT indication_raw_id AS raw_id, COUNT(*)::int AS cnt
    FROM allocation
    GROUP BY indication_raw_id
) p ON p.raw_id = r.id
LEFT JOIN (
    SELECT secondary_indication_raw_id AS raw_id, COUNT(*)::int AS cnt
    FROM allocation
    WHERE secondary_indication_raw_id IS NOT NULL
    GROUP BY secondary_indication_raw_id
) s ON s.raw_id = r.id
WHERE r.target_id = :normalizedId
  AND r.public_id IS NOT NULL
ORDER BY r.name ASC, r.id ASC
SQL,
            ['normalizedId' => $normalizedId],
        );

        return $rows;
    }

    /**
     * @param array<string, int> $statusCounts
     *
     * @return list<CatalogQualityWarning>
     */
    private function buildWarnings(array $statusCounts): array
    {
        $warnings = [];
        $needsReview = $statusCounts['needs_review'] ?? 0;
        if ($needsReview > 0) {
            $warnings[] = new CatalogQualityWarning(
                id: 'needs_review_for_target',
                labelKey: 'catalog.quality.warning.needs_review_for_target',
                count: $needsReview,
                severity: IndicationRawReviewHealthCheckSeverity::Warn,
                hintKey: 'catalog.quality.hint.needs_review_for_target',
                actionUrl: $this->urlGenerator->generate('app_explore_indication_raw_review_worklist', [
                    'segment' => 'needs_review',
                ]),
            );
        }

        $unreviewed = $statusCounts['unreviewed'] ?? 0;
        if ($unreviewed > 0) {
            $warnings[] = new CatalogQualityWarning(
                id: 'unreviewed_for_target',
                labelKey: 'catalog.quality.warning.unreviewed_for_target',
                count: $unreviewed,
                severity: IndicationRawReviewHealthCheckSeverity::Warn,
                hintKey: 'catalog.quality.hint.unreviewed_for_target',
                actionUrl: $this->urlGenerator->generate('app_explore_indication_raw_review_worklist', [
                    'segment' => 'unreviewed',
                ]),
            );
        }

        return $warnings;
    }

    private function parseDateTime(string|\DateTimeInterface|null $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
