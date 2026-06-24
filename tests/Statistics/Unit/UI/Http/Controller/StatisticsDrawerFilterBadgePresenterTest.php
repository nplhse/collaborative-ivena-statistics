<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\UI\Http\Controller;

use App\Statistics\UI\Http\Controller\StatisticsDrawerFilterBadgePresenter;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class StatisticsDrawerFilterBadgePresenterTest extends TestCase
{
    public function testPresentsReadableBadgesSortedByLabel(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => match ($key) {
            'label.gender' => 'Gender',
            'label.urgency' => 'Urgency',
            'label.requires_resus' => 'Requires resus',
            'label.yes' => 'Yes',
            default => $key,
        });

        $presenter = new StatisticsDrawerFilterBadgePresenter($translator);

        $badges = $presenter->present(
            [
                'gender' => '2',
                'urgency' => '1',
                'requiresResus' => '1',
                'department' => '',
            ],
            [
                'gender' => [2 => 'Female'],
                'urgency' => ['1' => 'Emergency Care'],
            ],
        );

        self::assertSame([
            ['label' => 'Gender', 'value' => 'Female'],
            ['label' => 'Requires resus', 'value' => 'Yes'],
            ['label' => 'Urgency', 'value' => 'Emergency Care'],
        ], $badges);
    }

    public function testPresentsAgeGroupInfectionAndBooleanNo(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => match ($key) {
            'label.age_group' => 'Age group',
            'label.infection' => 'Infection',
            'label.is_cpr' => 'CPR',
            'label.urgency' => 'Urgency',
            'label.yes' => 'Yes',
            'label.no' => 'No',
            'allocation.urgency.2' => 'Inpatient care',
            default => $key,
        });

        $presenter = new StatisticsDrawerFilterBadgePresenter($translator);

        $badges = $presenter->present(
            [
                'age_group' => 'under_18',
                'infection' => '5',
                'isCPR' => '0',
                'urgency' => '2',
                'department' => '',
            ],
            [
                'age_group' => ['under_18' => 'Under 18'],
                'infection' => [5 => 'MRSA'],
            ],
        );

        self::assertSame([
            ['label' => 'Age group', 'value' => 'Under 18'],
            ['label' => 'CPR', 'value' => 'No'],
            ['label' => 'Infection', 'value' => 'MRSA'],
            ['label' => 'Urgency', 'value' => 'Inpatient care'],
        ], $badges);
    }
}
