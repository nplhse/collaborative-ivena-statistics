<?php

declare(strict_types=1);

namespace App\Tests\Admin\Unit\Audit;

use App\Admin\Application\Audit\AuditTimelineFilter;
use PHPUnit\Framework\TestCase;

final class AuditTimelineFilterTest extends TestCase
{
    public function testParsesAndReproducesQuery(): void
    {
        $query = [
            'from' => '2026-11-01T00:00',
            'to' => '2026-11-13T12:30',
            'actor' => '7',
            'origin' => 'http',
            'action' => 'update',
            'intent' => 'user.admin.updated',
            'entity' => \App\User\Domain\Entity\User::class,
            'entityId' => '12',
            'request' => '0193a4b2-6f1e-7c55-9d3e-2a1b0c9d8e7f',
            'session' => '0193a4b2-6f1e-4c55-9d3e-2a1b0c9d8e7f',
            'outcome' => 'failed',
        ];

        $filter = AuditTimelineFilter::fromQuery($query);

        self::assertSame(7, $filter->actorId);
        self::assertFalse($filter->withoutActor);
        self::assertSame('2026-11-13 12:30:00', $filter->to?->format('Y-m-d H:i:s'));
        self::assertSame($query, $filter->toQuery());
        self::assertFalse($filter->isEmpty());
    }

    public function testActorNoneMeansEntriesWithoutActor(): void
    {
        $filter = AuditTimelineFilter::fromQuery(['actor' => 'none']);

        self::assertTrue($filter->withoutActor);
        self::assertNull($filter->actorId);
        self::assertSame(['actor' => 'none'], $filter->toQuery());
    }

    public function testInvalidValuesAreIgnored(): void
    {
        $filter = AuditTimelineFilter::fromQuery([
            'from' => 'yesterday',
            'actor' => '7; DROP',
            'origin' => 'ftp',
            'outcome' => 'maybe',
            'intent' => 'has space',
            'request' => str_repeat('a', 129),
            'action' => 'rename',
            'unknown' => 'x',
        ]);

        self::assertTrue($filter->isEmpty());
    }

    public function testWithRequestIdKeepsTheOtherCriteria(): void
    {
        $filter = AuditTimelineFilter::fromQuery(['origin' => 'http', 'request' => 'old'])->withRequestId('r1');

        self::assertSame(['origin' => 'http', 'request' => 'r1'], $filter->toQuery());
    }
}
