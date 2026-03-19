<?php

namespace App\Import\Infrastructure\Resolver;

use App\Allocation\Domain\Entity\MciCase;
use App\Import\Application\Contracts\MciCaseEntityResolverInterface;
use App\Import\Application\DTO\MciCaseRowDTO;
use App\Import\Infrastructure\Resolver\Strategy\DateParsingStrategy;

final class MciCaseDateResolver implements MciCaseEntityResolverInterface
{
    public function __construct(
        private readonly DateParsingStrategy $strategy,
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
        $this->strategy->apply($entity, $dto);
    }
}
