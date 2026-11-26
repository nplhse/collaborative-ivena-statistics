<?php

declare(strict_types=1);

namespace App\Allocation\Application\CaseFile;

use App\Allocation\Domain\Entity\Allocation;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Extension point for cards on the allocation case file (`/explore/allocation/{publicId}`).
 *
 * Other contexts add their own case-level data (for example the hospital course from encounter data)
 * without a dependency from Allocation on them. Providers check access themselves and return no
 * section when the current user may not see it. The case file is only rendered after the
 * AllocationVoter allowed the allocation.
 */
#[AutoconfigureTag(self::TAG)]
interface AllocationCaseSectionProviderInterface
{
    public const string TAG = 'allocation.case_section';

    /**
     * @return list<AllocationCaseSection>
     */
    public function sectionsFor(Allocation $allocation): array;
}
