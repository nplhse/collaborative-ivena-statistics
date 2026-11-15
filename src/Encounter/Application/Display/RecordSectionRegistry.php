<?php

declare(strict_types=1);

namespace App\Encounter\Application\Display;

use App\Encounter\Contract\Display\RecordSection;
use App\Encounter\Contract\Display\RecordSectionProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Record sections of every encounter schema for one encounter (extension point `encounter.record_section`,
 * ADR 037). Callers check VIEW_CASES on the encounter's hospital first.
 */
final readonly class RecordSectionRegistry
{
    /**
     * @param iterable<RecordSectionProviderInterface> $providers
     *
     * @psalm-suppress PossiblyUnusedMethod Autowired.
     */
    public function __construct(
        #[AutowireIterator(RecordSectionProviderInterface::TAG)]
        private iterable $providers,
    ) {
    }

    /**
     * @return list<RecordSection>
     */
    public function forEncounter(int $encounterId): array
    {
        $sections = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->sectionsForEncounter($encounterId) as $section) {
                $sections[] = $section;
            }
        }

        return $sections;
    }
}
