<?php

namespace App\Import\Application\Contracts;

use App\Import\Application\Exception\RowRejectException;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\AllocationRowType;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('import.allocation_row_processor')]
interface AllocationRowProcessorInterface
{
    public function type(): AllocationRowType;

    public function warm(): void;

    /**
     * @param array<string,string> $row
     *
     * @throws RowRejectException
     */
    public function process(array $row, Import $import, int $lineNo): void;
}
