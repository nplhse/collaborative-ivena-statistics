<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\DTO;

use App\Allocation\Application\Allocations\AllocationCreatedAtRange;
use App\Allocation\Application\Allocations\AllocationCreatedAtRangeParser;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class MciCaseQueryParametersDTO
{
    public function __construct(
        #[Assert\GreaterThan(0)]
        public int $page = 1,

        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 25,

        #[Assert\Choice(choices: ['asc', 'desc'])]
        public string $orderBy = 'desc',

        #[Assert\Choice(choices: ['createdAt', 'arrivalAt', 'mciTitle'])]
        public string $sortBy = 'createdAt',

        #[Assert\GreaterThan(0)]
        public ?int $importId = null,

        #[Assert\Length(max: 255)]
        public ?string $mciId = null,

        #[Assert\Length(max: 255)]
        public ?string $search = null,

        #[Assert\GreaterThan(0)]
        public ?int $hospital = null,

        #[Assert\GreaterThan(0)]
        public ?int $state = null,

        #[Assert\GreaterThan(0)]
        public ?int $dispatchArea = null,

        #[Assert\Length(max: 32)]
        public ?string $arrivalFrom = null,

        #[Assert\Length(max: 32)]
        public ?string $arrivalUntil = null,

        public ?string $urgency = null,

        public ?string $transportType = null,

        #[Assert\GreaterThan(0)]
        public ?int $department = null,

        #[Assert\GreaterThan(0)]
        public ?int $speciality = null,

        public ?int $departmentWasClosed = null,

        public ?int $requiresResus = null,

        public ?int $requiresCathlab = null,

        public ?int $isVentilated = null,

        public ?int $isShock = null,

        public ?int $isCPR = null,

        public ?int $isPregnant = null,

        public ?int $isWithPhysician = null,

        public ?int $indication = null,

        #[Assert\Regex(pattern: '/^(?:none|\d+)?$/')]
        public ?string $occasion = null,

        #[Assert\Regex(pattern: '/^(?:none|any|\d+)?$/')]
        public ?string $infection = null,
    ) {
    }

    public function normalizedMciId(): ?string
    {
        return $this->blankToNull($this->mciId);
    }

    public function normalizedSearch(): ?string
    {
        return $this->blankToNull($this->search);
    }

    public function arrivalFromDate(): ?string
    {
        return $this->arrivalAtRange()->fromDate;
    }

    public function arrivalUntilDate(): ?string
    {
        return $this->arrivalAtRange()->untilDate;
    }

    public function hasArrivalAtRange(): bool
    {
        return $this->arrivalAtRange()->isActive();
    }

    public function arrivalAtRange(): AllocationCreatedAtRange
    {
        return new AllocationCreatedAtRangeParser()->parse($this->arrivalFrom, $this->arrivalUntil);
    }

    private function blankToNull(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
