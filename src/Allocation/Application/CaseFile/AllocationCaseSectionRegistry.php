<?php

declare(strict_types=1);

namespace App\Allocation\Application\CaseFile;

use App\Allocation\Domain\Entity\Allocation;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class AllocationCaseSectionRegistry
{
    /**
     * @param iterable<AllocationCaseSectionProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(AllocationCaseSectionProviderInterface::TAG)]
        private iterable $providers,
    ) {
    }

    /**
     * @return list<AllocationCaseSection>
     */
    public function sectionsFor(Allocation $allocation): array
    {
        $sections = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->sectionsFor($allocation) as $section) {
                $sections[] = $section;
            }
        }

        return $sections;
    }
}
