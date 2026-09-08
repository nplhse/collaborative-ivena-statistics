<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\DTO;

use App\Allocation\Application\Allocations\AllocationListHospitalScopeResolver;
use App\Allocation\Application\Export\DTO\AllocationListFilterCriteria;
use App\Allocation\Application\Filter\OptionalRelationFilter;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class AllocationQueryParametersDTO
{
    public function __construct(
        #[Assert\GreaterThan(0)]
        public int $page = 1,

        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 50,

        #[Assert\Length(max: 2048)]
        public ?string $cursor = null,

        #[Assert\Choice(choices: ['asc', 'desc'])]
        public string $orderBy = 'desc',

        #[Assert\Choice(choices: ['age', 'arrivalAt'])]
        public string $sortBy = 'arrivalAt',

        #[Assert\GreaterThan(0)]
        public ?int $importId = null,

        public ?string $tier = null,

        public ?string $location = null,

        public ?string $size = null,

        public ?string $urgency = null,

        public ?int $dispatchArea = null,

        public ?int $state = null,

        public ?int $requiresResus = null,

        public ?int $requiresCathlab = null,

        public ?int $indication = null,

        #[Assert\Regex(pattern: '/^(?:none|\d+)?$/')]
        public ?string $secondaryIndication = null,

        #[Assert\Regex(pattern: '/^(?:none|any|\d+)?$/')]
        public ?string $secondaryTransport = null,

        public ?int $isVentilated = null,

        public ?int $isShock = null,

        public ?int $isCPR = null,

        public ?int $isPregnant = null,

        public ?int $isWorkAccident = null,

        #[Assert\Regex(pattern: '/^(?:0|1)?$/')]
        public ?string $isInfectious = null,

        #[Assert\Regex(pattern: '/^(?:none|any|\d+)?$/')]
        public ?string $infection = null,

        public ?int $department = null,

        public ?int $speciality = null,

        public ?int $assignment = null,

        #[Assert\Regex(pattern: '/^(?:none|\d+)?$/')]
        public ?string $occasion = null,

        public ?int $departmentWasClosed = null,

        public ?string $transportType = null,

        #[Assert\Choice(choices: [AllocationListHospitalScopeResolver::SCOPE_MY_HOSPITALS])]
        public ?string $hospitalScope = null,

        #[Assert\GreaterThan(0)]
        public ?int $hospital = null,

        #[Assert\Regex(pattern: '/^(my_hospitals|\d+)$/')]
        public ?string $hospitalFilter = null,
    ) {
    }

    public function resolvedHospitalFilter(): string
    {
        if (null !== $this->hospitalFilter && '' !== $this->hospitalFilter) {
            return $this->hospitalFilter;
        }

        if (AllocationListHospitalScopeResolver::SCOPE_MY_HOSPITALS === $this->hospitalScope) {
            if (null !== $this->hospital && $this->hospital > 0) {
                return (string) $this->hospital;
            }

            return AllocationListHospitalScopeResolver::SCOPE_MY_HOSPITALS;
        }

        return '';
    }

    public function toListFilterCriteria(): AllocationListFilterCriteria
    {
        [$infectionPresence, $infectionId] = $this->resolveInfectionFilter();

        return new AllocationListFilterCriteria(
            importId: $this->importId,
            tier: $this->tier,
            location: $this->location,
            size: $this->size,
            urgency: $this->urgency,
            dispatchArea: $this->dispatchArea,
            state: $this->state,
            requiresResus: $this->requiresResus,
            requiresCathlab: $this->requiresCathlab,
            indication: $this->indication,
            secondaryIndication: $this->secondaryIndication,
            secondaryTransport: $this->secondaryTransport,
            isVentilated: $this->isVentilated,
            isShock: $this->isShock,
            isCPR: $this->isCPR,
            isPregnant: $this->isPregnant,
            isWorkAccident: $this->isWorkAccident,
            isInfectious: $infectionPresence,
            infection: $infectionId,
            department: $this->department,
            speciality: $this->speciality,
            assignment: $this->assignment,
            occasion: $this->occasion,
            departmentWasClosed: $this->departmentWasClosed,
            transportType: $this->transportType,
        );
    }

    private function optionalTriStateInt(?string $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if ('0' === $value) {
            return 0;
        }

        return '1' === $value ? 1 : null;
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function resolveInfectionFilter(): array
    {
        $fromInfectionParam = OptionalRelationFilter::fromQuery($this->infection);
        if ($fromInfectionParam->isActive()) {
            return $fromInfectionParam->toPresenceAndId();
        }

        return OptionalRelationFilter::fromPresenceAndValue(
            match ($this->optionalTriStateInt($this->isInfectious)) {
                0 => false,
                1 => true,
                default => null,
            },
            null,
        )->toPresenceAndId();
    }
}
