<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Application\Notification;

use App\Storage\Application\Notification\CapacityAlertDirection;
use App\Storage\Application\Notification\CapacityLevelChange;
use App\Storage\Domain\Enum\CapacityLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CapacityLevelChangeTest extends TestCase
{
    /**
     * Every pair of known levels.
     *
     * @return iterable<string, array{CapacityLevel, CapacityLevel, CapacityAlertDirection|null}>
     */
    public static function transitions(): iterable
    {
        $known = array_values(array_filter(CapacityLevel::cases(), static fn (CapacityLevel $level): bool => $level->isKnown()));

        foreach ($known as $from) {
            foreach ($known as $to) {
                $direction = match (true) {
                    $from === $to => null,
                    (int) $to->rank() > (int) $from->rank() => CapacityAlertDirection::Escalated,
                    CapacityLevel::Normal === $to => CapacityAlertDirection::Resolved,
                    default => CapacityAlertDirection::DeEscalated,
                };

                yield $from->value.' to '.$to->value => [$from, $to, $direction];
            }
        }
    }

    #[DataProvider('transitions')]
    public function testEveryLevelChangeAlertsInItsDirection(CapacityLevel $from, CapacityLevel $to, ?CapacityAlertDirection $direction): void
    {
        $change = CapacityLevelChange::between($from, $to);

        if (!$direction instanceof CapacityAlertDirection) {
            self::assertNull($change, 'an unchanged level sends nothing');

            return;
        }

        self::assertNotNull($change);
        self::assertSame($from, $change->from);
        self::assertSame($to, $change->to);
        self::assertSame($direction, $change->direction());
    }

    public function testExamplesOfTheAdr(): void
    {
        self::assertSame(CapacityAlertDirection::Escalated, CapacityLevelChange::between(CapacityLevel::Normal, CapacityLevel::Notice)?->direction());
        self::assertSame(CapacityAlertDirection::Escalated, CapacityLevelChange::between(CapacityLevel::Notice, CapacityLevel::Critical)?->direction());
        self::assertSame(CapacityAlertDirection::DeEscalated, CapacityLevelChange::between(CapacityLevel::Critical, CapacityLevel::Warning)?->direction());
        self::assertSame(CapacityAlertDirection::Resolved, CapacityLevelChange::between(CapacityLevel::Notice, CapacityLevel::Normal)?->direction());
    }

    public function testAnUnknownLevelIsNoChange(): void
    {
        foreach (CapacityLevel::cases() as $lastNotified) {
            self::assertNull(CapacityLevelChange::between($lastNotified, CapacityLevel::Unknown));
        }
    }

    public function testAnUnknownLastLevelCountsAsNormal(): void
    {
        $change = CapacityLevelChange::between(CapacityLevel::Unknown, CapacityLevel::Warning);

        self::assertSame(CapacityLevel::Normal, $change?->from);
        self::assertNull(CapacityLevelChange::between(CapacityLevel::Unknown, CapacityLevel::Normal));
    }
}
