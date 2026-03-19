<?php

namespace App\Import\Infrastructure\Resolver;

use App\Allocation\Domain\Entity\MciCase;
use App\Import\Application\Contracts\MciCaseEntityResolverInterface;
use App\Import\Application\DTO\MciCaseRowDTO;
use App\Import\Infrastructure\Resolver\Strategy\SpecialityDepartmentReferenceStrategy;

final class MciCaseSpecialityResolver implements MciCaseEntityResolverInterface
{
    public function __construct(
        private readonly SpecialityDepartmentReferenceStrategy $strategy,
    ) {
    }

    #[\Override]
    public function warm(): void
    {
        $this->strategy->warm();
    }

    #[\Override]
    public function supports(MciCase $entity, MciCaseRowDTO $dto): bool
    {
        return true;
    }

    #[\Override]
    public function apply(MciCase $entity, MciCaseRowDTO $dto): void
    {
        // MciCase: departmentWasClosed ist optional und bleibt im Entity nullbar.
        $this->strategy->apply(
            $entity,
            $dto->speciality,
            $dto->department,
            $dto->departmentWasClosed,
            static fn (?bool $v): ?bool => $v,
        );
    }
}
