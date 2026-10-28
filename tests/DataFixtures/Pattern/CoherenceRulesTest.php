<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Pattern;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\DataFixtures\Generator\Infrastructure\Random\RandomStreams;
use App\DataFixtures\Pattern\Application\CoherenceRules;
use PHPUnit\Framework\TestCase;

final class CoherenceRulesTest extends TestCase
{
    public function testPregnancyNeedsAWomanAged15To49(): void
    {
        self::assertFalse($this->apply(['pregnant' => true, 'gender' => AllocationGender::MALE])['pregnant']);
        self::assertFalse($this->apply(['pregnant' => true, 'age' => 80])['pregnant']);
        self::assertFalse($this->apply(['pregnant' => true, 'age' => 12])['pregnant']);
        self::assertTrue($this->apply(['pregnant' => true, 'age' => 30])['pregnant']);
    }

    public function testPaediatricsGetsChildren(): void
    {
        $case = $this->apply(['field' => CoherenceRules::PAEDIATRICS, 'age' => 70]);

        self::assertLessThan(18, $case['age']);
        self::assertGreaterThanOrEqual(1, $case['age']);
        self::assertSame(70, $this->apply(['field' => 'Innere Medizin', 'age' => 70])['age']);
    }

    public function testCprMeansUrgencyOneAndTheResuscitationRoom(): void
    {
        $case = $this->apply(['cpr' => true, 'urgency' => AllocationUrgency::OUTPATIENT, 'resus' => false]);

        self::assertSame(AllocationUrgency::EMERGENCY, $case['urgency']);
        self::assertTrue($case['resus']);
    }

    public function testShockMeansUrgencyOne(): void
    {
        self::assertSame(AllocationUrgency::EMERGENCY, $this->apply(['shock' => true, 'urgency' => AllocationUrgency::INPATIENT])['urgency']);
    }

    public function testACoherentCaseStaysAsItIs(): void
    {
        $case = $this->case([]);

        self::assertSame($case, CoherenceRules::apply($case, new RandomStreams()->stream(1, 'h', '2026-01', RandomStreams::ALLOCATIONS)));
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array{gender: AllocationGender, age: int, urgency: AllocationUrgency, field: string, pregnant: bool, cpr: bool, resus: bool, shock: bool}
     */
    private function apply(array $values): array
    {
        return CoherenceRules::apply($this->case($values), new RandomStreams()->stream(1, 'h', '2026-01', RandomStreams::ALLOCATIONS));
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array{gender: AllocationGender, age: int, urgency: AllocationUrgency, field: string, pregnant: bool, cpr: bool, resus: bool, shock: bool}
     */
    private function case(array $values): array
    {
        /** @var array{gender: AllocationGender, age: int, urgency: AllocationUrgency, field: string, pregnant: bool, cpr: bool, resus: bool, shock: bool} $case */
        $case = $values + [
            'gender' => AllocationGender::FEMALE,
            'age' => 30,
            'urgency' => AllocationUrgency::INPATIENT,
            'field' => 'Innere Medizin',
            'pregnant' => false,
            'cpr' => false,
            'resus' => false,
            'shock' => false,
        ];

        return $case;
    }
}
