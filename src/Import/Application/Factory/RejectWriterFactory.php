<?php

namespace App\Import\Application\Factory;

use App\Import\Application\Contracts\RejectWriterInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class RejectWriterFactory
{
    /** @var array<string, RejectWriterInterface> */
    private array $writers = [];

    /**
     * @param iterable<RejectWriterInterface> $writers
     */
    public function __construct(
        #[AutowireIterator(tag: 'import.reject_writer')]
        iterable $writers,
        #[Autowire('%app.import.reject_writer%')]
        private readonly string $defaultType,
    ) {
        foreach ($writers as $writer) {
            $this->writers[$writer->getType()] = $writer;
        }
    }

    public function create(?string $type = null): RejectWriterInterface
    {
        $type ??= $this->defaultType;

        if (!isset($this->writers[$type])) {
            throw new \InvalidArgumentException(\sprintf('Unknown reject writer "%s". Available: %s', $type, implode(', ', array_keys($this->writers))));
        }

        return $this->writers[$type];
    }
}
