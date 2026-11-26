<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\EditSection;

use App\Allocation\Domain\Entity\Hospital;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Extension point for links in the sidebar of the hospital edit pages.
 *
 * Other contexts add their own hospital-scoped pages here (for example encounter access grants)
 * without a dependency from Allocation on them. Providers check access themselves and return no
 * section when the current user may not open it.
 */
#[AutoconfigureTag(self::TAG)]
interface HospitalEditSectionProviderInterface
{
    public const string TAG = 'allocation.hospital_edit_section';

    /**
     * @return list<HospitalEditSection>
     */
    public function sectionsFor(Hospital $hospital): array;
}
