<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\UI\Http\DTO;

use App\Content\UI\Http\DTO\ActivityTimelineQueryParametersDTO;
use App\User\Domain\Enum\UserActivityType;
use PHPUnit\Framework\TestCase;

final class ActivityTimelineQueryParametersDTOTest extends TestCase
{
    public function testNormalizesBlankAndInvalidValues(): void
    {
        $dto = new ActivityTimelineQueryParametersDTO(
            from: 'not-a-date',
            until: '2026-13-40',
            type: 'hospital_disassociated',
            user: '  ',
            search: '',
            cursor: 'abc',
        );

        self::assertNull($dto->from);
        self::assertNull($dto->until);
        self::assertNull($dto->type);
        self::assertNull($dto->user);
        self::assertNull($dto->search);
        self::assertSame('abc', $dto->cursor);
        self::assertFalse($dto->hasActiveFilters());
        self::assertSame([], $dto->toQueryParams());
    }

    public function testMapsValidFilters(): void
    {
        $dto = new ActivityTimelineQueryParametersDTO(
            from: '2026-03-01',
            until: '2026-03-31',
            type: 'post_published',
            user: ' Alice ',
            search: ' clinic ',
        );

        self::assertTrue($dto->hasActiveFilters());
        self::assertSame(
            [
                'from' => '2026-03-01',
                'until' => '2026-03-31',
                'type' => 'post_published',
                'user' => 'Alice',
                'search' => 'clinic',
            ],
            $dto->toQueryParams(),
        );

        $filters = $dto->toFilters();
        self::assertSame('2026-03-01 00:00:00', $filters->from?->format('Y-m-d H:i:s'));
        self::assertSame('2026-04-01 00:00:00', $filters->untilExclusive?->format('Y-m-d H:i:s'));
        self::assertSame(UserActivityType::POST_PUBLISHED, $filters->type);
        self::assertSame('Alice', $filters->username);
        self::assertSame('clinic', $filters->search);
    }
}
