<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator\Dataset;

use App\Allocation\Domain\Enum\HospitalTier;
use App\DataFixtures\Generator\Application\Dataset\DatasetHospitalPlan;
use App\DataFixtures\Generator\Application\Dataset\DatasetPeriod;
use App\DataFixtures\Generator\Application\Dataset\DatasetPlan;
use App\DataFixtures\Generator\Application\Dataset\DatasetRequest;
use App\DataFixtures\Generator\Application\Dataset\HospitalPeriodData;
use App\DataFixtures\Generator\Application\Dataset\ProfileSelectionCodec;
use App\DataFixtures\Generator\Application\ProfileSelection;
use App\DataFixtures\Generator\Application\Run\GenerationRunScope;
use App\DataFixtures\Structure\Dto\StrokeUnitLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The value objects of the dataset engine (#877): period, request scopes, plan, profile settings, month state.
 */
final class DatasetPlanTest extends TestCase
{
    public function testTheDemoWindowEndsWithTheMonthBeforeLast(): void
    {
        $period = DatasetPeriod::demoDefault(new MockClock('2026-10-28 14:00:00'), 12);

        self::assertSame(['2025-09', '2026-08'], [$period->from, $period->to], 'September 2026 stays for the CSV upload');
        self::assertCount(12, $period->months());
        self::assertSame('2026-09-01', $period->end()->format('Y-m-d'));
        self::assertSame('2026-08-31', $period->lastDay(new \DateTimeImmutable('2026-10-27'))->format('Y-m-d'));
        self::assertSame('2026-08-15', $period->lastDay(new \DateTimeImmutable('2026-08-15 10:00'))->format('Y-m-d'), 'never after yesterday');
        self::assertSame(['2026-01', '2026-01'], [DatasetPeriod::demoDefault(new MockClock('2026-03-01'), 1)->from, DatasetPeriod::demoDefault(new MockClock('2026-03-31'), 1)->to]);
    }

    public function testAPeriodIsOrderedAndBounded(): void
    {
        self::assertSame(['2025-12', '2026-01', '2026-02'], DatasetPeriod::between('2025-12', '2026-02')->months());

        $this->expectException(\InvalidArgumentException::class);
        DatasetPeriod::between('2026-02', '2026-01');
    }

    /**
     * @return iterable<string, array{GenerationRunScope, list<int>}>
     */
    public static function invalidScopes(): iterable
    {
        yield 'one without a hospital' => [GenerationRunScope::One, []];
        yield 'one with two' => [GenerationRunScope::One, [1, 2]];
        yield 'selected without a hospital' => [GenerationRunScope::Selected, []];
        yield 'all with hospitals' => [GenerationRunScope::AllParticipating, [1]];
        yield 'a hospital twice' => [GenerationRunScope::Selected, [1, 1]];
    }

    /**
     * @param list<int> $ids
     */
    #[DataProvider('invalidScopes')]
    public function testTheScopeIsExplicit(GenerationRunScope $scope, array $ids): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DatasetRequest($scope, $ids, DatasetPeriod::between('2026-01', '2026-01'));
    }

    public function testBlockedHospitalsRefuseTheRunUntilTheyAreLeftOut(): void
    {
        $request = new DatasetRequest(GenerationRunScope::Selected, [1, 2, 3], DatasetPeriod::between('2026-01', '2026-02'));
        $ready = new DatasetHospitalPlan(1, 'Ready', [], generate: ['2026-01', '2026-02'], plannedAllocations: 120);
        $done = new DatasetHospitalPlan(2, 'Done', [], keep: ['2026-01', '2026-02']);
        $blocked = new DatasetHospitalPlan(3, 'Blocked', ['it has no owner'], generate: ['2026-01']);
        $plan = new DatasetPlan($request, 740, 'defaults', new \DateTimeImmutable('2026-02-28'), [], [$ready, $done, $blocked]);

        self::assertFalse($plan->isRunnable());
        self::assertSame(['Blocked: it has no owner'], $plan->runBlockers());

        $filtered = $plan->withoutBlocked();
        self::assertTrue($filtered->isRunnable());
        self::assertSame([$ready], $filtered->withWork(), 'a hospital with every month kept has no work');
        self::assertSame([$blocked], $filtered->skipped);
        self::assertSame(120, $filtered->plannedAllocations());

        $nothing = new DatasetPlan($request, 740, 'defaults', new \DateTimeImmutable('2026-02-28'), [], [$done]);
        self::assertStringContainsString('nothing to generate', $nothing->runBlockers()[0]);
    }

    public function testAProfileSelectionSurvivesTheRunSettings(): void
    {
        $selection = new ProfileSelection(cathlab: false, strokeUnit: StrokeUnitLevel::cases()[0], tier: HospitalTier::FULL, annualVolume: 24000, closureShare: 0.05);
        $encoded = ProfileSelectionCodec::encode($selection);

        self::assertSame(['cathlab' => false, 'stroke_unit' => StrokeUnitLevel::cases()[0]->value, 'tier' => HospitalTier::FULL->value, 'annual_volume' => 24000, 'closure_share' => 0.05], $encoded, 'only set fields');
        $decoded = ProfileSelectionCodec::decode(json_decode(json_encode($encoded, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR));
        self::assertEquals($selection, $decoded);
        self::assertEquals(new ProfileSelection(closureShare: 0.0), ProfileSelectionCodec::decode(['closure_share' => 0]), 'whole numbers are shares too');

        $this->expectException(\InvalidArgumentException::class);
        ProfileSelectionCodec::decode(['annual_volume' => 1000, 'volume' => 1]);
    }

    public function testAMonthIsCompleteWithBothImportsAndAllTheirRows(): void
    {
        $allocations = ['id' => 10, 'type' => 'allocations', 'seed' => 740, 'row_count' => 90, 'rows' => 90, 'projected' => false];
        $closures = ['id' => 11, 'type' => 'closures', 'seed' => 740, 'row_count' => 4, 'rows' => 4, 'projected' => false];
        $data = new HospitalPeriodData(
            real: ['2026-03' => [99]],
            generated: [
                '2026-01' => [$allocations, $closures],
                '2026-02' => [['rows' => 50] + $allocations, $closures],
                '2026-04' => [['projected' => true] + $allocations, $closures],
                '2026-05' => [$allocations],
            ],
            scenarios: [['id' => 20, 'months' => ['2026-02']], ['id' => 21, 'months' => ['2026-01', '2026-02']]],
        );

        self::assertTrue($data->isComplete('2026-01'));
        self::assertFalse($data->isComplete('2026-02'), 'a killed process wrote 50 of 90 allocations');
        self::assertFalse($data->isComplete('2026-05'), 'no closure import');
        self::assertFalse($data->isComplete('2026-06'));
        self::assertSame(['allocations' => 10, 'closures' => 11], $data->unprojected('2026-01'));
        self::assertNull($data->unprojected('2026-04'));
        self::assertNull($data->unprojected('2026-02'), 'an incomplete month is replaced, not projected');
        self::assertSame(['2026-03'], $data->realMonths());
        self::assertSame([740], $data->seeds('2026-01'));
        self::assertSame(['2026-01', '2026-02'], $data->scenarioMonths());
        self::assertSame([20, 21], $data->scenarioImportIds());
    }
}
