<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Entity;

use App\Allocation\Domain\Enum\DistrictBoundaryFetchStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Boundary of a district with its provenance and the state of its fetch. Separate from `District` and not audited, so
 * lists do not load polygons and the audit log does not store them (ADR 041).
 */
#[ORM\Entity]
class DistrictGeometry
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: District::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private District $district;

    /** GeoJSON geometry in WGS84; null until a boundary is stored. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $geometry = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $geometryProvider = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $geometryLicence = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $geometryAttribution = null;

    /** Null for boundaries whose retrieval date is not known. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $geometryFetchedAt = null;

    #[ORM\Column(length: 16, enumType: DistrictBoundaryFetchStatus::class, options: ['default' => 'idle'])]
    private DistrictBoundaryFetchStatus $fetchStatus = DistrictBoundaryFetchStatus::Idle;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $fetchAttemptId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fetchError = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $fetchRequestedAt = null;

    public function __construct(District $district)
    {
        $this->district = $district;
    }

    public function getDistrict(): District
    {
        return $this->district;
    }

    public function getGeometry(): ?string
    {
        return $this->geometry;
    }

    public function getGeometryProvider(): ?string
    {
        return $this->geometryProvider;
    }

    public function getGeometryLicence(): ?string
    {
        return $this->geometryLicence;
    }

    public function getGeometryAttribution(): ?string
    {
        return $this->geometryAttribution;
    }

    public function getGeometryFetchedAt(): ?\DateTimeImmutable
    {
        return $this->geometryFetchedAt;
    }

    public function getFetchStatus(): DistrictBoundaryFetchStatus
    {
        return $this->fetchStatus;
    }

    public function getFetchAttemptId(): ?Uuid
    {
        return $this->fetchAttemptId;
    }

    public function getFetchError(): ?string
    {
        return $this->fetchError;
    }

    public function getFetchRequestedAt(): ?\DateTimeImmutable
    {
        return $this->fetchRequestedAt;
    }

    /**
     * Stores a boundary with its provenance. Any running fetch is over: its attempt may no longer write.
     */
    public function storeGeometry(
        string $geometry,
        string $provider,
        ?string $licence,
        ?string $attribution,
        ?\DateTimeImmutable $fetchedAt,
    ): void {
        $this->geometry = $geometry;
        $this->geometryProvider = $provider;
        $this->geometryLicence = $licence;
        $this->geometryAttribution = $attribution;
        $this->geometryFetchedAt = $fetchedAt;
        $this->fetchStatus = DistrictBoundaryFetchStatus::Idle;
        $this->fetchAttemptId = null;
        $this->fetchError = null;
        $this->fetchRequestedAt = null;
    }
}
