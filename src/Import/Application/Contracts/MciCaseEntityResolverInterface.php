<?php

namespace App\Import\Application\Contracts;

use App\Allocation\Domain\Entity\MciCase;
use App\Import\Application\DTO\MciCaseRowDTO;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('mci_case.import_resolver')]
interface MciCaseEntityResolverInterface
{
    public function warm(): void;

    public function supports(MciCase $entity, MciCaseRowDTO $dto): bool;

    public function apply(MciCase $entity, MciCaseRowDTO $dto): void;
}
