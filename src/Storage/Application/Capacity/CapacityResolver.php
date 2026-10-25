<?php

declare(strict_types=1);

namespace App\Storage\Application\Capacity;

use App\Storage\Application\Contract\CapacityProviderInterface;
use App\Storage\Application\StorageSettings;
use App\Storage\Domain\Enum\CapacitySource;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Picks the configured capacity reference and falls back to the budget when it is not available.
 * Without either there is no reference and the level is unknown.
 */
final readonly class CapacityResolver
{
    /** @var array<string, CapacityProviderInterface> */
    private array $providers;

    /**
     * @param iterable<CapacityProviderInterface> $providers
     *
     * @psalm-suppress PossiblyUnusedMethod Wired by the container.
     */
    public function __construct(
        private StorageSettings $settings,
        #[AutowireIterator(CapacityProviderInterface::TAG)]
        iterable $providers,
    ) {
        $bySource = [];
        foreach ($providers as $provider) {
            $bySource[$provider->source()->value] = $provider;
        }

        $this->providers = $bySource;
    }

    public function resolve(?int $measuredUsedBytes, ?FilesystemSpace $filesystem): ?CapacityReference
    {
        $preferred = $this->settings->preferredCapacitySource;
        $reference = ($this->providers[$preferred->value] ?? null)?->provide($measuredUsedBytes, $filesystem);

        if (null === $reference && CapacitySource::Budget !== $preferred) {
            return ($this->providers[CapacitySource::Budget->value] ?? null)?->provide($measuredUsedBytes, $filesystem);
        }

        return $reference;
    }
}
