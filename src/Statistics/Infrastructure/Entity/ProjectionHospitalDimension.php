<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Read-only materialized view: one row per hospital with projection dimension codes.
 *
 * @psalm-suppress MissingConstructor
 * @psalm-suppress ClassMustBeFinal Not final: Doctrine may generate proxies for entities.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'mv_projection_hospital_dimensions')]
class ProjectionHospitalDimension
{
    #[ORM\Id]
    #[ORM\Column(name: 'hospital_id')]
    private int $hospitalId;

    #[ORM\Column(name: 'state_id', nullable: true)]
    private ?int $stateId = null;

    #[ORM\Column(name: 'dispatch_area_id', nullable: true)]
    private ?int $dispatchAreaId = null;

    #[ORM\Column(name: 'hospital_location_code', nullable: true)]
    private ?int $hospitalLocationCode = null;

    #[ORM\Column(name: 'hospital_tier_code', nullable: true)]
    private ?int $hospitalTierCode = null;

    public function getHospitalId(): int
    {
        return $this->hospitalId;
    }

    public function getStateId(): ?int
    {
        return $this->stateId;
    }

    public function getDispatchAreaId(): ?int
    {
        return $this->dispatchAreaId;
    }

    public function getHospitalLocationCode(): ?int
    {
        return $this->hospitalLocationCode;
    }

    public function getHospitalTierCode(): ?int
    {
        return $this->hospitalTierCode;
    }
}
