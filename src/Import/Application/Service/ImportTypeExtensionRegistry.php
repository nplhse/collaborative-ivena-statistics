<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Application\Contracts\ImportTypeExtensionInterface;
use App\Import\Domain\Enum\ImportType;
use App\User\Domain\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Import type extensions (`import.type_extension`) by import type.
 */
final readonly class ImportTypeExtensionRegistry
{
    /** @var array<string, ImportTypeExtensionInterface> */
    private array $extensions;

    /**
     * @param iterable<ImportTypeExtensionInterface> $extensions
     *
     * @psalm-suppress PossiblyUnusedMethod Autowired.
     */
    public function __construct(
        #[AutowireIterator(ImportTypeExtensionInterface::TAG)]
        iterable $extensions = [],
    ) {
        $byType = [];
        foreach ($extensions as $extension) {
            $byType[$extension::importType()->value] = $extension;
        }

        $this->extensions = $byType;
    }

    public function for(?ImportType $type): ?ImportTypeExtensionInterface
    {
        return $type instanceof ImportType ? $this->extensions[$type->value] ?? null : (null);
    }

    /**
     * @return list<ImportTypeExtensionInterface>
     */
    public function all(): array
    {
        return array_values($this->extensions);
    }

    /**
     * @return list<ImportTypeExtensionInterface>
     */
    public function availableTo(User $user): array
    {
        return array_values(array_filter($this->extensions, static fn (ImportTypeExtensionInterface $extension): bool => $extension->isAvailableTo($user)));
    }
}
