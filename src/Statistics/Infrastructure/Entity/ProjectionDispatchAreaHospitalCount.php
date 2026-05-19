<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Read-only materialized view: distinct hospitals per dispatch area in allocation_stats_projection.
 *
 * @psalm-suppress MissingConstructor
 * @psalm-suppress ClassMustBeFinal Not final: Doctrine may generate proxies for entities.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'mv_projection_dispatch_area_hospital_count')]
class ProjectionDispatchAreaHospitalCount
{
    #[ORM\Id]
    #[ORM\Column(name: 'dispatch_area_id')]
    private int $dispatchAreaId;

    #[ORM\Column(name: 'hospital_count')]
    private int $hospitalCount;

    public function getDispatchAreaId(): int
    {
        return $this->dispatchAreaId;
    }

    public function getHospitalCount(): int
    {
        return $this->hospitalCount;
    }
}
