<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Resolver;

use App\Allocation\Domain\Entity\Allocation;
use App\Import\Application\Contracts\AllocationEntityResolverInterface;
use App\Import\Application\DTO\AllocationRowDTO;
use App\Import\Infrastructure\CaseId\CaseIdHasher;

final readonly class AllocationSupplementaryFieldsResolver implements AllocationEntityResolverInterface
{
    public function __construct(
        private CaseIdHasher $caseIdHasher,
    ) {
    }

    #[\Override]
    public function warm(): void
    {
    }

    #[\Override]
    public function supports(Allocation $entity, AllocationRowDTO $dto): bool
    {
        return true;
    }

    #[\Override]
    public function apply(Allocation $entity, AllocationRowDTO $dto): void
    {
        $entity->setCaseIdHash($this->caseIdHasher->hashFrom($dto->caseId));
        $entity->setNotes($dto->notes);
    }
}
