<?php

namespace App\Query;

use Doctrine\DBAL\Connection;

/** @psalm-suppress ClassMustBeFinal */
readonly class PublicYearStatsQuery
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * @return ?array<string,int>
     */
    public function fetch(int $year): ?array
    {
        $periodKey = sprintf('%04d-01-01', $year);

        $sql = <<<SQL
            SELECT
                total,
                gender_m,
                gender_w,
                gender_d,
                gender_u,
                urg_1,
                urg_2,
                urg_3,
                cathlab_required,
                resus_required,
                is_cpr,
                is_ventilated,
                is_shock,
                is_pregnant,
                with_physician,
                infectious,
                computed_at
            FROM agg_allocations_counts
            WHERE scope_type   = 'public'
              AND scope_id     = 'all'
              AND period_gran  = 'year'
              AND period_key   = :period_key::date
            LIMIT 1
            SQL;

        $row = $this->db->fetchAssociative($sql, ['period_key' => $periodKey]);

        if (false === $row) {
            return null;
        }

        return $row;
    }
}
