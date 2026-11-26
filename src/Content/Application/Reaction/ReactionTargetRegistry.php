<?php

declare(strict_types=1);

namespace App\Content\Application\Reaction;

use App\Content\Application\Contract\ReactionTargetProviderInterface;
use App\Content\Domain\Enum\ReactionTargetType;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** The reaction target providers by target type (#467). */
final readonly class ReactionTargetRegistry
{
    /** @var array<string, ReactionTargetProviderInterface> */
    private array $providers;

    /**
     * @param iterable<ReactionTargetProviderInterface> $providers
     *
     * @psalm-suppress PossiblyUnusedMethod Symfony autowires this service
     */
    public function __construct(
        #[AutowireIterator(ReactionTargetProviderInterface::TAG)]
        iterable $providers,
    ) {
        $byType = [];
        foreach ($providers as $provider) {
            $byType[$provider->targetType()->value] = $provider;
        }
        $this->providers = $byType;
    }

    public function get(ReactionTargetType $type): ?ReactionTargetProviderInterface
    {
        return $this->providers[$type->value] ?? null;
    }

    /**
     * @return list<ReactionTargetProviderInterface>
     */
    public function all(): array
    {
        return array_values($this->providers);
    }
}
