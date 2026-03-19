<?php

namespace App\Import\Infrastructure\Resolver;

use App\Allocation\Domain\Entity\MciCase;
use App\Import\Application\Contracts\MciCaseEntityResolverInterface;
use App\Import\Application\DTO\MciCaseRowDTO;
use App\Import\Infrastructure\Resolver\Strategy\FlagMappingStrategy;

final class MciCaseFlagResolver implements MciCaseEntityResolverInterface
{
    public function __construct(
        private readonly FlagMappingStrategy $strategy,
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
        // MciCase: dto null bleibt null (Entity-Booleans sind optional).
        $this->strategy->apply($entity, $dto, false);
    }
}
