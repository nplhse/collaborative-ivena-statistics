<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Plugin\InpatientStay\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One synthetic inpatient stay. InpatientStayHandler writes stays with SQL (insert-if-new, so that a
 * known stay is a duplicate without a race), the pages read them through the ORM. References hospital, import (cascade) and its encounter (RESTRICT, ADR 037) by id only: the plugin uses
 * no entity of the application. Constraints are in the bundle's migration.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'test_inpatient_stay')]
class InpatientStay
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column]
        private int $hospitalId,
        #[ORM\Column]
        private int $keyGeneration,
        #[ORM\Column]
        private int $importId,
        #[ORM\Column(nullable: true)]
        private ?int $encounterId,
        #[ORM\Column(length: 64)]
        private string $stayFingerprint,
        #[ORM\Column(length: 64)]
        private string $caseKey,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $admittedAt,
        #[ORM\Column(length: 8, nullable: true)]
        private ?string $dischargeDiagnosis,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $dischargedAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHospitalId(): int
    {
        return $this->hospitalId;
    }

    public function getKeyGeneration(): int
    {
        return $this->keyGeneration;
    }

    public function getImportId(): int
    {
        return $this->importId;
    }

    public function getEncounterId(): ?int
    {
        return $this->encounterId;
    }

    public function getStayFingerprint(): string
    {
        return $this->stayFingerprint;
    }

    public function getCaseKey(): string
    {
        return $this->caseKey;
    }

    public function getAdmittedAt(): \DateTimeImmutable
    {
        return $this->admittedAt;
    }

    public function getDischargeDiagnosis(): ?string
    {
        return $this->dischargeDiagnosis;
    }

    public function getDischargedAt(): ?\DateTimeImmutable
    {
        return $this->dischargedAt;
    }
}
