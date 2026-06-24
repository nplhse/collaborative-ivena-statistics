<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO;

final readonly class StatisticsDrawerFilter
{
    public function __construct(
        public ?int $gender = null,
        public ?int $urgency = null,
        public ?string $ageGroup = null,
        public ?int $department = null,
        public ?int $speciality = null,
        public ?bool $requiresResus = null,
        public ?bool $requiresCathlab = null,
        public ?bool $isVentilated = null,
        public ?bool $isShock = null,
        public ?bool $isCpr = null,
        public ?bool $isPregnant = null,
        public ?bool $isWorkAccident = null,
        public ?bool $isInfectious = null,
        public ?int $infection = null,
    ) {
    }

    public function isActive(): bool
    {
        return null !== $this->gender
            || null !== $this->urgency
            || null !== $this->ageGroup
            || null !== $this->department
            || null !== $this->speciality
            || null !== $this->requiresResus
            || null !== $this->requiresCathlab
            || null !== $this->isVentilated
            || null !== $this->isShock
            || null !== $this->isCpr
            || null !== $this->isPregnant
            || null !== $this->isWorkAccident
            || null !== $this->isInfectious
            || null !== $this->infection;
    }
}
