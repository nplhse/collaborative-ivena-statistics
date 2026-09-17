<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\InsightCompare;

use App\Statistics\UI\Http\Controller\IndicationGroupComparePickerViewModelFactory;
use PHPUnit\Framework\TestCase;

final class IndicationGroupComparePickerViewModelFactoryTest extends TestCase
{
    public function testCreatePresetsReturnsEmptyForNoMembers(): void
    {
        self::assertSame([], new IndicationGroupComparePickerViewModelFactory()->createPresets([]));
    }

    public function testCreatePresetsChooseSideBOnly(): void
    {
        $presets = new IndicationGroupComparePickerViewModelFactory()->createPresets([
            ['indicationId' => 11, 'label' => 'Largest'],
            ['indicationId' => 7, 'label' => 'Middle'],
            ['indicationId' => 3, 'label' => 'Smallest'],
        ]);

        self::assertCount(2, $presets);
        self::assertSame(11, $presets[0]['indicationId']);
        self::assertSame('Largest', $presets[0]['labelB']);
        self::assertSame(3, $presets[1]['indicationId']);
        self::assertSame('Smallest', $presets[1]['labelB']);
        self::assertArrayNotHasKey('indicationIdA', $presets[0]);
        self::assertArrayNotHasKey('labelA', $presets[0]);
    }

    public function testSingleMemberOnlyYieldsTopPreset(): void
    {
        $presets = new IndicationGroupComparePickerViewModelFactory()->createPresets([
            ['indicationId' => 11, 'label' => 'Only'],
        ]);

        self::assertCount(1, $presets);
        self::assertSame(11, $presets[0]['indicationId']);
    }
}
