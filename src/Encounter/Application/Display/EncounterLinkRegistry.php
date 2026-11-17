<?php

declare(strict_types=1);

namespace App\Encounter\Application\Display;

use App\Encounter\Contract\Display\EncounterLinkProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Links to the record pages of encounters from every encounter schema (extension point `encounter.link`, #1300).
 * Without a schema that has record pages, encounters have no link.
 */
final readonly class EncounterLinkRegistry
{
    /**
     * @param iterable<EncounterLinkProviderInterface> $providers
     *
     * @psalm-suppress PossiblyUnusedMethod Autowired.
     */
    public function __construct(
        #[AutowireIterator(EncounterLinkProviderInterface::TAG)]
        private iterable $providers,
    ) {
    }

    /**
     * @param list<int> $encounterIds
     *
     * @return array<int, string> URL by encounter id; the first provider wins
     */
    public function forEncounters(array $encounterIds): array
    {
        $links = [];
        foreach ($this->providers as $provider) {
            $missing = array_values(array_diff($encounterIds, array_keys($links)));
            if ([] === $missing) {
                break;
            }

            $links += $provider->linksForEncounters($missing);
        }

        return $links;
    }
}
