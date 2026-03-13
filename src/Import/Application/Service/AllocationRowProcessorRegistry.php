<?php

namespace App\Import\Application\Service;

use App\Import\Application\Contracts\AllocationRowProcessorInterface;
use App\Import\Domain\Enum\AllocationRowType;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class AllocationRowProcessorRegistry
{
    /** @var array<string,AllocationRowProcessorInterface> */
    private array $processorsByType = [];

    /**
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @param iterable<AllocationRowProcessorInterface> $processors
     */
    public function __construct(
        #[AutowireIterator(tag: 'import.allocation_row_processor')]
        iterable $processors,
    ) {
        foreach ($processors as $processor) {
            $type = $processor->type()->value;

            if (isset($this->processorsByType[$type])) {
                throw new \LogicException(\sprintf('Duplicate row processor for type "%s".', $type));
            }

            $this->processorsByType[$type] = $processor;
        }
    }

    public function warmAll(): void
    {
        foreach ($this->processorsByType as $processor) {
            $processor->warm();
        }
    }

    public function get(AllocationRowType $type): AllocationRowProcessorInterface
    {
        if (!isset($this->processorsByType[$type->value])) {
            throw new \LogicException(\sprintf('No row processor registered for type "%s".', $type->value));
        }

        return $this->processorsByType[$type->value];
    }
}
