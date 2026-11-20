<?php

declare(strict_types=1);

namespace App\Encounter\Application\Display;

use App\Encounter\Contract\Display\EncounterListDetail;
use App\Encounter\Contract\Display\EncounterListDetailProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * List details of every encounter schema for many encounters (extension point `encounter.list_detail`, #1203).
 * Callers check VIEW_CASES on the encounters' hospitals first.
 */
final readonly class EncounterListDetailRegistry
{
    /**
     * @param iterable<EncounterListDetailProviderInterface> $providers
     *
     * @psalm-suppress PossiblyUnusedMethod Autowired.
     */
    public function __construct(
        #[AutowireIterator(EncounterListDetailProviderInterface::TAG)]
        private iterable $providers,
    ) {
    }

    /**
     * @param list<int> $encounterIds
     *
     * @return array<int, list<EncounterListDetail>> by encounter id, in provider order
     */
    public function forEncounters(array $encounterIds): array
    {
        if ([] === $encounterIds) {
            return [];
        }

        $details = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->detailsForEncounters($encounterIds) as $encounterId => $detail) {
                $details[$encounterId][] = $detail;
            }
        }

        return $details;
    }
}
