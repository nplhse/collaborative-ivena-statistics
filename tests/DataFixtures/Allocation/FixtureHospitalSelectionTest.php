<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Allocation;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\DataFixtures\Allocation\FixtureHospitalSelection;
use App\DataFixtures\FixtureVolume;
use PHPUnit\Framework\TestCase;

final class FixtureHospitalSelectionTest extends TestCase
{
    /** @var list<Hospital> */
    private array $participating = [];

    public function testMinimalTakesOneHospitalPerSizeWithTheMatchingTier(): void
    {
        $active = $this->selection()->active($this->volume(['Large' => 1, 'Medium' => 1, 'Small' => 1]), $this->participating);

        self::assertSame(['L Full', 'M Extended', 'S Basic'], $this->names($active));
    }

    public function testMediumTakesTwoPerSizeAndFallsBackToOtherTiers(): void
    {
        $active = $this->selection()->active($this->volume(['Large' => 2, 'Medium' => 2, 'Small' => 2]), $this->participating);

        self::assertSame(['L Full', 'L Extended', 'M Extended', 'M Basic', 'S Basic', 'S Extended'], $this->names($active));
    }

    public function testNonParticipatingHospitalsAreNeverSelected(): void
    {
        $active = $this->selection()->active($this->volume(['Large' => 5, 'Medium' => 5, 'Small' => 5]), $this->participating);

        self::assertNotContains('L Full (not participating)', $this->names($active));
        self::assertCount(6, $active);
    }

    public function testSingleDrawsOneHospitalAndTheDemoUserOwnsIt(): void
    {
        $volume = $this->volume(['any' => 1]);
        $active = $this->selection()->active($volume, $this->participating);

        self::assertCount(1, $active);
        self::assertSame($active[0], $this->selection()->demoHospital($volume, $active));
    }

    public function testTheDemoHospitalIsOneOfTheActiveOnesAndRepeatsWithTheSeed(): void
    {
        $volume = $this->volume(['Large' => 2, 'Medium' => 2, 'Small' => 2]);
        $active = $this->selection()->active($volume, $this->participating);

        $demo = $this->selection()->demoHospital($volume, $active);

        self::assertContains($demo, $active);
        self::assertSame($demo, $this->selection()->demoHospital($volume, array_reverse($active)), 'independent of the order');

        $others = [];
        foreach (range(1, 20) as $seed) {
            $others[] = $this->selection()->demoHospital($this->volume(['Large' => 2, 'Medium' => 2, 'Small' => 2], $seed), $active)->getName();
        }
        self::assertGreaterThan(1, \count(array_unique($others)), 'other seeds choose other hospitals');
    }

    /**
     * @param array<string, int> $hospitals
     */
    private function volume(array $hospitals, int $seed = 740): FixtureVolume
    {
        return new FixtureVolume('test', $hospitals, 12, ['Small' => 10.0, 'Medium' => 15.0, 'Large' => 40.0], 1.0, 0, $seed, false);
    }

    private function selection(): FixtureHospitalSelection
    {
        $hospitals = [
            $this->hospital(1, 'L Extended', HospitalSize::LARGE, HospitalTier::EXTENDED),
            $this->hospital(2, 'L Full', HospitalSize::LARGE, HospitalTier::FULL),
            $this->hospital(3, 'L Full (not participating)', HospitalSize::LARGE, HospitalTier::FULL, participating: false),
            $this->hospital(4, 'M Basic', HospitalSize::MEDIUM, HospitalTier::BASIC),
            $this->hospital(5, 'M Extended', HospitalSize::MEDIUM, HospitalTier::EXTENDED),
            $this->hospital(6, 'S Basic', HospitalSize::SMALL, HospitalTier::BASIC),
            $this->hospital(7, 'S Extended', HospitalSize::SMALL, HospitalTier::EXTENDED),
        ];
        $this->participating = array_values(array_filter($hospitals, static fn (Hospital $hospital): bool => $hospital->isParticipating()));

        return new FixtureHospitalSelection();
    }

    private function hospital(int $id, string $name, HospitalSize $size, HospitalTier $tier, bool $participating = true): Hospital
    {
        $hospital = new Hospital()->setName($name)->setSize($size)->setTier($tier)->setIsParticipating($participating);
        new \ReflectionProperty(Hospital::class, 'id')->setValue($hospital, $id);

        return $hospital;
    }

    /**
     * @param list<Hospital> $hospitals
     *
     * @return list<string>
     */
    private function names(array $hospitals): array
    {
        return array_map(static fn (Hospital $hospital): string => (string) $hospital->getName(), $hospitals);
    }
}
