<?php

declare(strict_types=1);

namespace App\LegacyMigration\Application\Contract;

use App\Allocation\Domain\Entity\Allocation;
use App\Import\Application\DTO\AllocationRowDTO;
use App\Import\Domain\Entity\Import;

interface LegacyAllocationImportFactoryInterface
{
    public function warm(): void;

    public function fromDto(AllocationRowDTO $dto, Import $import): Allocation;
}
