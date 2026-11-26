<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\MyHospitals\Action;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Extension point for quick actions on the My Hospitals cards (#1128).
 *
 * Other contexts add a button per hospital (for example the encounter analytics) without a dependency from
 * Allocation on them. Providers get all hospitals of the page at once, check access themselves and
 * return actions only for hospitals the current user may open the target for. Keep it to a constant
 * number of queries per page, not one per hospital.
 */
#[AutoconfigureTag(self::TAG)]
interface MyHospitalActionProviderInterface
{
    public const string TAG = 'allocation.my_hospital_action';

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<int, list<MyHospitalAction>> actions by hospital id; hospitals without actions may be missing
     */
    public function actionsFor(array $hospitalIds): array;
}
