<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Hospital;

use App\Allocation\Application\Contracts\DispatchAreaLookupInterface;
use App\Allocation\Application\Contracts\HospitalLookupInterface;
use App\Allocation\Application\Contracts\StateLookupInterface;
use App\Allocation\Application\Hospital\HospitalGeoScope;
use App\Allocation\Application\Hospital\HospitalGeoScopeResolver;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\State;
use PHPUnit\Framework\TestCase;

final class HospitalGeoScopeResolverTest extends TestCase
{
    public function testResolvesSingleHospitalIncludingNonParticipating(): void
    {
        $hospital = $this->hospital('Einzelklinik', participating: false);
        $resolver = $this->resolver(hospital: $hospital);

        $resolution = $resolver->resolve(HospitalGeoScope::hospital(12, participatingOnly: true));

        self::assertTrue($resolution->success);
        self::assertSame('Einzelklinik', $resolution->label);
        self::assertSame([$hospital], $resolution->hospitals);
    }

    public function testUnknownHospitalFails(): void
    {
        $resolution = $this->resolver()->resolve(HospitalGeoScope::hospital(99));

        self::assertFalse($resolution->success);
        self::assertSame('Unknown hospital #99.', $resolution->error);
    }

    public function testDispatchAreaCanRestrictToParticipatingHospitals(): void
    {
        $area = new DispatchArea()->setName('Frankfurt');
        $participating = $this->hospital('Teilnehmend', participating: true);
        $nonParticipating = $this->hospital('Nicht teilnehmend', participating: false);
        $resolver = $this->resolver(
            dispatchArea: $area,
            hospitals: [$participating, $nonParticipating],
        );

        $all = $resolver->resolve(HospitalGeoScope::dispatchArea(7));
        $onlyParticipating = $resolver->resolve(HospitalGeoScope::dispatchArea(7, participatingOnly: true));

        self::assertTrue($all->success);
        self::assertSame('Frankfurt', $all->label);
        self::assertSame([$participating, $nonParticipating], $all->hospitals);
        self::assertSame([$participating], $onlyParticipating->hospitals);
    }

    public function testUnknownDispatchAreaFails(): void
    {
        $resolution = $this->resolver()->resolve(HospitalGeoScope::dispatchArea(4));

        self::assertFalse($resolution->success);
        self::assertSame('Unknown dispatch area #4.', $resolution->error);
    }

    public function testStateCanRestrictToParticipatingHospitals(): void
    {
        $state = new State()->setName('Hessen');
        $participating = $this->hospital('Teilnehmend', participating: true);
        $nonParticipating = $this->hospital('Nicht teilnehmend', participating: false);
        $resolver = $this->resolver(
            state: $state,
            hospitals: [$participating, $nonParticipating],
        );

        $all = $resolver->resolve(HospitalGeoScope::state(1));
        $onlyParticipating = $resolver->resolve(HospitalGeoScope::state(1, participatingOnly: true));

        self::assertSame([$participating, $nonParticipating], $all->hospitals);
        self::assertSame([$participating], $onlyParticipating->hospitals);
        self::assertSame('Hessen', $all->label);
    }

    public function testUnknownStateFails(): void
    {
        $resolution = $this->resolver()->resolve(HospitalGeoScope::state(99));

        self::assertFalse($resolution->success);
        self::assertSame('Unknown federal state #99.', $resolution->error);
    }

    /**
     * @param list<Hospital> $hospitals
     */
    private function resolver(
        ?Hospital $hospital = null,
        ?DispatchArea $dispatchArea = null,
        ?State $state = null,
        array $hospitals = [],
    ): HospitalGeoScopeResolver {
        $hospitalLookup = $this->createStub(HospitalLookupInterface::class);
        $hospitalLookup->method('findById')->willReturn($hospital);
        $hospitalLookup->method('findByDispatchArea')->willReturn($hospitals);
        $hospitalLookup->method('findByState')->willReturn($hospitals);

        $dispatchAreaLookup = $this->createStub(DispatchAreaLookupInterface::class);
        $dispatchAreaLookup->method('findById')->willReturn($dispatchArea);

        $stateLookup = $this->createStub(StateLookupInterface::class);
        $stateLookup->method('findById')->willReturn($state);

        return new HospitalGeoScopeResolver($hospitalLookup, $dispatchAreaLookup, $stateLookup);
    }

    private function hospital(string $name, bool $participating): Hospital
    {
        return new Hospital()
            ->setName($name)
            ->setIsParticipating($participating);
    }
}
