<?php

namespace App\Import\Infrastructure\Resolver;

use App\Allocation\Domain\Entity\MciCase;
use App\Import\Application\Contracts\MciCaseEntityResolverInterface;
use App\Import\Application\DTO\MciCaseRowDTO;

final class MciCaseMciResolver implements MciCaseEntityResolverInterface
{
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
        if (!\is_string($dto->mciId) || '' === $dto->mciId) {
            throw new \LogicException('mciId must be a non-empty string after validation');
        }

        if (!\is_string($dto->mciTitle) || '' === $dto->mciTitle) {
            throw new \LogicException('mciTitle must be a non-empty string after validation');
        }

        $entity->setMciId($dto->mciId);
        $entity->setMciTitle($dto->mciTitle);
    }
}
