<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\ClosureAnalytics\Application\ClosureDurationDistribution;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureDurationLoad;
use App\Statistics\ClosureAnalytics\UI\Http\Controller\ClosureDurationLoadLabeler;
use App\Statistics\HospitalPopulation\Application\DescriptiveStatisticsCalculator;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ClosureDurationLoadLabelerTest extends TestCase
{
    public function testEmptyLoadHasNoShareAndNoNormalizedTime(): void
    {
        $load = ClosureDurationLoad::empty();

        self::assertFalse($load->hasEvaluableTime());
        self::assertSame(0.0, $load->sharePercent(10));
        self::assertSame(0, $load->normalizedSeconds(10));
        self::assertNull($load->minutes(null));
        self::assertSame(0, $load->atLeastOneSeconds());
    }

    public function testRelabelsReasonsAndSortsByMedianThenLabel(): void
    {
        $distribution = new ClosureDurationDistribution(new DescriptiveStatisticsCalculator());
        $load = ClosureDurationLoad::empty()->withReasons([
            $distribution->summarize('no_bed_capacity', 'no_bed_capacity', [100]),
            $distribution->summarize('', '', [300]),
            $distribution->summarize('overload', 'overload', [200]),
            $distribution->summarize('technical_fault', 'technical_fault', [200]),
        ]);

        $labeled = $this->labeler()->label($load);

        self::assertSame(
            ['', 'overload', 'technical_fault', 'no_bed_capacity'],
            array_map(static fn (\App\Statistics\ClosureAnalytics\Application\DTO\ClosureDurationDistributionGroup $group): string => $group->key, $labeled->reasons),
        );
        self::assertSame(
            ['No reason given', 'Overload', 'Technical fault', 'No bed capacity'],
            array_map(static fn (\App\Statistics\ClosureAnalytics\Application\DTO\ClosureDurationDistributionGroup $group): string => $group->label, $labeled->reasons),
        );
        self::assertSame([], $labeled->specialities);
    }

    private function labeler(): ClosureDurationLoadLabeler
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => match ($id) {
                'stats.closure.duration_load.reason_missing' => 'No reason given',
                'stats.closure.reason.no_bed_capacity' => 'No bed capacity',
                'stats.closure.reason.overload' => 'Overload',
                'stats.closure.reason.technical_fault' => 'Technical fault',
                default => $id,
            },
        );

        return new ClosureDurationLoadLabeler($translator);
    }
}
