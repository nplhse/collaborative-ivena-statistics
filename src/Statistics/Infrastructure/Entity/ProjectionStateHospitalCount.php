<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Read-only materialized view: distinct hospitals per state in allocation_stats_projection.
 *
 * @psalm-suppress MissingConstructor
 * @psalm-suppress ClassMustBeFinal Not final: Doctrine may generate proxies for entities.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'mv_projection_state_hospital_count')]
class ProjectionStateHospitalCount
{
    #[ORM\Id]
    #[ORM\Column(name: 'state_id')]
    private int $stateId;

    #[ORM\Column(name: 'hospital_count')]
    private int $hospitalCount;

    public function getStateId(): int
    {
        return $this->stateId;
    }

    public function getHospitalCount(): int
    {
        return $this->hospitalCount;
    }
}
