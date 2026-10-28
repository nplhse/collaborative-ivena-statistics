<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Pattern;

use App\Allocation\Domain\Enum\AllocationUrgency;
use App\DataFixtures\Pattern\Application\PatternCouplings;
use PHPUnit\Framework\TestCase;

final class PatternCouplingsTest extends TestCase
{
    private const array OCCASIONS = ['Häuslicher Einsatz' => 0.60, 'Öffentlicher Raum' => 0.20, 'Verkehrsunfall' => 0.15, 'Arbeitsunfall' => 0.05];

    public function testAccidentOccasionsBelongToInjuries(): void
    {
        self::assertSame(self::OCCASIONS, PatternCouplings::occasions(self::OCCASIONS, '272'), 'an injury keeps the whole table');
        self::assertSame(['Häuslicher Einsatz' => 0.60, 'Öffentlicher Raum' => 0.20], PatternCouplings::occasions(self::OCCASIONS, '322'), 'a syncope never comes from a traffic accident');
        self::assertSame(['Häuslicher Einsatz' => 0.60, 'Öffentlicher Raum' => 0.20], PatternCouplings::occasions(self::OCCASIONS, null), 'no indication');
        self::assertSame(['Verkehrsunfall' => 1.0], PatternCouplings::occasions(['Verkehrsunfall' => 1.0], '322'), 'a table of accidents only stays as it is');
    }

    public function testRespiratoryCodesDrawInfluenzaMoreOften(): void
    {
        $infections = ['Sonstiges' => 0.60, 'Influenza' => 0.05, 'V.a. COVID' => 0.05, 'MRSA' => 0.30];

        // 0.05 × 5 = 0.25: influenza is a quarter of the weight 1.40, about 18 % instead of 5 %.
        self::assertSame(['Sonstiges' => 0.60, 'Influenza' => 0.25, 'V.a. COVID' => 0.25, 'MRSA' => 0.30], PatternCouplings::infections($infections, '311'));
        self::assertSame(['Sonstiges' => 0.60, 'Influenza' => 0.25, 'V.a. COVID' => 0.25, 'MRSA' => 0.30], PatternCouplings::infections($infections, '315'));
        self::assertSame($infections, PatternCouplings::infections($infections, '322'), 'other codes keep the table');
        self::assertSame($infections, PatternCouplings::infections($infections, null));
    }

    public function testWeekendNightsGetMoreWeight(): void
    {
        $hours = [0 => 2.0, 5 => 2.0, 6 => 2.0, 12 => 6.0];

        self::assertSame($hours, PatternCouplings::hours($hours, new \DateTimeImmutable('2026-01-09')), 'Friday');
        self::assertEqualsWithDelta([0 => 2.6, 5 => 2.6, 6 => 2.0, 12 => 6.0], PatternCouplings::hours($hours, new \DateTimeImmutable('2026-01-10')), 1e-9, 'Saturday');
        self::assertEqualsWithDelta([0 => 2.6, 5 => 2.6, 6 => 2.0, 12 => 6.0], PatternCouplings::hours($hours, new \DateTimeImmutable('2026-01-11')), 1e-9, 'Sunday');
    }

    public function testTransportTimesAreRoundedAndShorterForUrgencyThree(): void
    {
        self::assertSame(23, PatternCouplings::transportMinutes(23, AllocationUrgency::EMERGENCY, false));
        self::assertSame(25, PatternCouplings::transportMinutes(23, AllocationUrgency::EMERGENCY, true));
        self::assertSame(20, PatternCouplings::transportMinutes(22, AllocationUrgency::INPATIENT, true));
        // 25 × 0.8 = 20
        self::assertSame(20, PatternCouplings::transportMinutes(25, AllocationUrgency::OUTPATIENT, false));
        // 22 × 0.8 = 17.6 → 18, rounded to 20
        self::assertSame(18, PatternCouplings::transportMinutes(22, AllocationUrgency::OUTPATIENT, false));
        self::assertSame(20, PatternCouplings::transportMinutes(22, AllocationUrgency::OUTPATIENT, true));
        self::assertSame(5, PatternCouplings::transportMinutes(1, AllocationUrgency::INPATIENT, true), 'never 0 minutes');
        self::assertSame(1, PatternCouplings::transportMinutes(1, AllocationUrgency::OUTPATIENT, false));
    }
}
