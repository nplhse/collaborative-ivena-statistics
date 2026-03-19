<?php

namespace App\Import\Infrastructure\Resolver;

use App\Allocation\Domain\Entity\MciCase;
use App\Import\Application\Contracts\MciCaseEntityResolverInterface;
use App\Import\Application\DTO\MciCaseRowDTO;
use App\Import\Infrastructure\Resolver\Strategy\EnumMappingStrategy;

final class MciCaseEnumResolver implements MciCaseEntityResolverInterface
{
    public function __construct(
        private readonly EnumMappingStrategy $strategy,
    ) {
    }

    #[\Override]
    public function warm(): void
    {
    }

    #[\Override]
    public function supports(MciCase $entity, MciCaseRowDTO $dto): bool
    {
        return true;
    }

    #[\Override]
    public function apply(MciCase $entity, MciCaseRowDTO $dto): void
    {
        // MciCase: Gender/Urgency optional (optional=false impliziert Pflichtfelder).
        $this->strategy->apply($entity, $dto, genderOptional: true, urgencyOptional: true);
    }
}
