<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Application;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use Random\Randomizer;

/**
 * Rules that hold for every sampled case, whatever the distributions say (ADR 022).
 *
 * - pregnant patients are female and 15 to 49 years old; otherwise the flag is dropped,
 * - paediatric departments get patients younger than 18,
 * - CPR means urgency 1 and the resuscitation room,
 * - shock means urgency 1.
 */
final class CoherenceRules
{
    public const string PAEDIATRICS = 'Pädiatrie';

    /**
     * @param array{gender: AllocationGender, age: int, urgency: AllocationUrgency, field: string, pregnant: bool, cpr: bool, resus: bool, shock: bool} $case
     *
     * @return array{gender: AllocationGender, age: int, urgency: AllocationUrgency, field: string, pregnant: bool, cpr: bool, resus: bool, shock: bool}
     */
    public static function apply(array $case, Randomizer $random): array
    {
        if (self::PAEDIATRICS === $case['field'] && $case['age'] >= 18) {
            $case['age'] = $random->getInt(1, 17);
        }
        if ($case['pregnant'] && (AllocationGender::FEMALE !== $case['gender'] || $case['age'] < 15 || $case['age'] > 49)) {
            $case['pregnant'] = false;
        }
        if ($case['cpr']) {
            $case['urgency'] = AllocationUrgency::EMERGENCY;
            $case['resus'] = true;
        }
        if ($case['shock']) {
            $case['urgency'] = AllocationUrgency::EMERGENCY;
        }

        return $case;
    }
}
