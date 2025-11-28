<?php

namespace App\Allocation\Domain\Entity;

use App\Allocation\Domain\Enum\AssessmentAirway;
use App\Allocation\Domain\Enum\AssessmentBreathing;
use App\Allocation\Domain\Enum\AssessmentCirculation;
use App\Allocation\Domain\Enum\AssessmentDisability;
use App\Allocation\Infrastructure\Repository\AssessmentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AssessmentRepository::class)]
class Assessment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: AssessmentAirway::class)]
    private ?AssessmentAirway $airway = null;

    #[ORM\Column(enumType: AssessmentBreathing::class)]
    private ?AssessmentBreathing $breathing = null;

    #[ORM\Column(enumType: AssessmentCirculation::class)]
    private ?AssessmentCirculation $circulation = null;

    #[ORM\Column(enumType: AssessmentDisability::class)]
    private ?AssessmentDisability $disability = null;

    #[ORM\Column()]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAirway(): ?AssessmentAirway
    {
        return $this->airway;
    }

    public function setAirway(?AssessmentAirway $airway): void
    {
        $this->airway = $airway;
    }

    public function getBreathing(): ?AssessmentBreathing
    {
        return $this->breathing;
    }

    public function setBreathing(?AssessmentBreathing $breathing): void
    {
        $this->breathing = $breathing;
    }

    public function getCirculation(): ?AssessmentCirculation
    {
        return $this->circulation;
    }

    public function setCirculation(?AssessmentCirculation $circulation): void
    {
        $this->circulation = $circulation;
    }

    public function getDisability(): ?AssessmentDisability
    {
        return $this->disability;
    }

    public function setDisability(?AssessmentDisability $disability): void
    {
        $this->disability = $disability;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function isValid(): bool
    {
        return
            null !== $this->airway
            && null !== $this->breathing
            && null !== $this->circulation
            && null !== $this->disability;
    }
}
