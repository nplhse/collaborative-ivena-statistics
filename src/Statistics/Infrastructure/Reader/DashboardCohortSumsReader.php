<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Reader;

use App\Statistics\Domain\Model\CohortSumsView;
use App\Statistics\Domain\Model\Scope;
use Doctrine\DBAL\Connection;

/** @psalm-suppress ClassMustBeFinal */
readonly class DashboardCohortSumsReader
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(private Connection $db)
    {
    }

    public function read(Scope $scope): ?CohortSumsView
    {
        $row = $this->db->fetchAssociative(
            'SELECT *, computed_at
               FROM agg_allocations_cohort_sums
              WHERE scope_type = :t AND scope_id = :i
                AND period_gran = :g AND period_key = :k',
            [
                't' => $scope->scopeType,
                'i' => $scope->scopeId,
                'g' => $scope->granularity,
                'k' => $scope->periodKey,
            ]
        );

        if (false === $row) {
            return null;
        }

        return new CohortSumsView(
            scope: $scope,
            total: (int) $row['total'],
            computedAt: new \DateTimeImmutable((string) $row['computed_at']),
            genderM: (int) $row['gender_m'],
            genderW: (int) $row['gender_w'],
            genderD: (int) $row['gender_d'],
            genderU: (int) $row['gender_u'],
            urg1: (int) $row['urg_1'],
            urg2: (int) $row['urg_2'],
            urg3: (int) $row['urg_3'],
            cathlabRequired: (int) $row['cathlab_required'],
            resusRequired: (int) $row['resus_required'],
            isCpr: (int) $row['is_cpr'],
            isVentilated: (int) $row['is_ventilated'],
            isShock: (int) $row['is_shock'],
            isPregnant: (int) $row['is_pregnant'],
            withPhysician: (int) $row['with_physician'],
            infectious: (int) $row['infectious'],
        );
    }
}
