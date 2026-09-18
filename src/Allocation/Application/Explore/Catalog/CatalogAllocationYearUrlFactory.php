<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\Catalog;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds Allocation-list drill-down URLs for catalog yearly coverage cells.
 */
final readonly class CatalogAllocationYearUrlFactory
{
    public const string DATE_QUERY_FORMAT = 'Y-m-d\TH:i:s';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param array<string, bool|float|int|string> $entityFilters
     * @param list<array{year: int, count: int}>   $years
     *
     * @return array<int, string>
     */
    public function forYears(array $entityFilters, array $years): array
    {
        $urls = [];
        foreach ($years as $row) {
            if ($row['count'] <= 0) {
                continue;
            }

            $year = $row['year'];
            $urls[$year] = $this->forYear($entityFilters, $year);
        }

        return $urls;
    }

    /**
     * @param array<string, bool|float|int|string> $entityFilters
     */
    public function forYear(array $entityFilters, int $year): string
    {
        $from = new \DateTimeImmutable(sprintf('%d-01-01 00:00:00', $year));

        return $this->urlGenerator->generate('app_explore_allocation_list', array_merge($entityFilters, [
            'createdFrom' => $from->format(self::DATE_QUERY_FORMAT),
            'createdToExclusive' => $from->modify('+1 year')->format(self::DATE_QUERY_FORMAT),
        ]));
    }
}
