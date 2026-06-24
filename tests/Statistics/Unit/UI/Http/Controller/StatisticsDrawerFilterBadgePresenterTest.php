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
}
