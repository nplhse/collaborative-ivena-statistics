<?php

declare(strict_types=1);

namespace App\Tests\Research\Unit\Domain\Enum;

use App\Research\Domain\Enum\TimelineEventType;
use PHPUnit\Framework\TestCase;

/**
 * Every timeline event has a translation key and an icon that exists in assets/icons/tabler (ADR 045).
 */
final class TimelineEventTypeTest extends TestCase
{
    public function testEveryEventHasALabelAndAnExistingIcon(): void
    {
        foreach (TimelineEventType::cases() as $type) {
            self::assertSame('data_request.timeline.event.'.$type->value, $type->labelKey());
            self::assertStringStartsWith('tabler:', $type->icon());
            self::assertFileExists(\dirname(__DIR__, 5).'/assets/icons/tabler/'.substr($type->icon(), 7).'.svg', $type->value);
        }
    }
}
