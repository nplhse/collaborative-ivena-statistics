<?php

declare(strict_types=1);

namespace App\LegacyMigration\Infrastructure\Adapter;

use App\Allocation\Domain\Entity\Allocation;
use App\Import\Application\DTO\AllocationRowDTO;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Mapping\AllocationImportFactory;
use App\LegacyMigration\Application\Contract\LegacyAllocationImportFactoryInterface;

/** @psalm-suppress UnusedClass Wired via services.yaml alias to LegacyAllocationImportFactoryInterface. */
final readonly class AllocationImportFactoryAdapter implements LegacyAllocationImportFactoryInterface
{
    public function __construct(
        private AllocationImportFactory $inner,
    ) {
    }

    #[\Override]
    public function warm(): void
    {
        $this->inner->warm();
    }

    #[\Override]
    public function fromDto(AllocationRowDTO $dto, Import $import): Allocation
    {
        return $this->inner->fromDto($dto, $import);
    }
}
