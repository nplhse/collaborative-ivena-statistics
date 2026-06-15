<?php

declare(strict_types=1);

namespace App\Statistics\Application;

final class ChartBucketMapper
{
    /**
     * @param list<string>          $bucketKeys
     * @param array<int|string,int> $bucketedCounts
     *
     * @return list<int>
     */
    public function mapMonthlyCounts(array $bucketKeys, array $bucketedCounts): array
    {
        $base = array_fill_keys($bucketKeys, 0);
        foreach ($bucketedCounts as $key => $count) {
            if (\array_key_exists($key, $base)) {
                $base[$key] = $count;
            }
        }

        return array_values($base);
    }

    /**
     * @param array<int, array{year: int, month: int, count: int}> $rows
     *
     * @return array<string,int>
     */
    public function monthRowsToBucketCounts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $counts[sprintf('%04d-%02d', $row['year'], $row['month'])] = $row['count'];
        }

        return $counts;
    }

    /**
     * @param array<int, array{year: int, count: int}> $rows
     *
     * @return array<int,int>
     */
    public function yearRowsToBucketCounts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['year']] = $row['count'];
        }

        return $counts;
    }
}
