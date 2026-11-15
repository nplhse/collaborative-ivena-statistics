<?php

declare(strict_types=1);

namespace App\Encounter\Application\Import;

use App\Encounter\Contract\Import\EncounterSchemaHandlerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * All schema handlers known to the server, by schema name (extension point `encounter.schema_handler`,
 * ADR 037). A schema can only be uploaded and processed when it has a field contract
 * (EncounterDimensionRegistry) and a handler.
 */
final readonly class EncounterSchemaHandlerRegistry
{
    /** @var array<string, EncounterSchemaHandlerInterface> */
    private array $handlers;

    /**
     * @param iterable<EncounterSchemaHandlerInterface> $handlers
     *
     * @psalm-suppress PossiblyUnusedMethod Autowired.
     */
    public function __construct(
        #[AutowireIterator(EncounterSchemaHandlerInterface::TAG)]
        iterable $handlers,
    ) {
        $bySchema = [];
        foreach ($handlers as $handler) {
            $schema = $handler->schema();
            if (isset($bySchema[$schema])) {
                throw new \LogicException(sprintf('Encounter schema "%s" has two handlers (%s, %s).', $schema, $bySchema[$schema]::class, $handler::class));
            }

            $bySchema[$schema] = $handler;
        }

        ksort($bySchema);
        $this->handlers = $bySchema;
    }

    public function has(string $schema): bool
    {
        return isset($this->handlers[$schema]);
    }

    public function find(string $schema): ?EncounterSchemaHandlerInterface
    {
        return $this->handlers[$schema] ?? null;
    }

    /**
     * @return list<EncounterSchemaHandlerInterface>
     */
    public function all(): array
    {
        return array_values($this->handlers);
    }
}
