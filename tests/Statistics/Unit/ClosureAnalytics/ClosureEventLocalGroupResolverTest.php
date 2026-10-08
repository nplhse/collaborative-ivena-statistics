<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\ClosureAnalytics\Application\ClosureEventLocalGroupResolver;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventType;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureIntervalRow;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class ClosureEventLocalGroupResolverTest extends TestCase
{
    public function testResolveBuildsLabelsFromAssignedSourceGroupsOnly(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllKeyValue')
            ->willReturn(['grp-a' => '42']);

        $resolver = new ClosureEventLocalGroupResolver($connection);
        $members = [
            $this->member(sourceGroupId: 'grp-a', closureUnit: 'Ward A', eventKey: '99'),
            $this->member(sourceGroupId: null, closureUnit: 'Other'),
            $this->member(sourceGroupId: 'grp-b', closureUnit: 'Ward B', eventKey: '99'),
        ];

        $groups = $resolver->resolve(1, '99', $members);

        self::assertCount(2, $groups);
        self::assertSame('grp-a', $groups[0]->sourceGroupId);
        self::assertSame('Ward A', $groups[0]->label);
        self::assertSame('42', $groups[0]->eventKey);
        self::assertFalse($groups[0]->current);
        self::assertSame('grp-b', $groups[1]->sourceGroupId);
        self::assertNull($groups[1]->eventKey);
    }

    public function testResolveMarksCurrentGroupEvent(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllKeyValue')->willReturn(['grp-a' => '7']);

        $resolver = new ClosureEventLocalGroupResolver($connection);
        $groups = $resolver->resolve(3, '7', [$this->member(sourceGroupId: 'grp-a', closureUnit: 'Unit')]);

        self::assertTrue($groups[0]->current);
    }

    private function member(
        ?string $sourceGroupId = null,
        ?string $closureUnit = null,
        string $eventKey = '1',
    ): ClosureIntervalRow {
        return new ClosureIntervalRow(
            id: 1,
            hospitalId: 1,
            hospitalName: 'Hospital',
            specialityName: 'Spec',
            departmentName: 'Dept',
            startsAt: new \DateTimeImmutable('2026-05-01 10:00:00'),
            endsAt: new \DateTimeImmutable('2026-05-01 12:00:00'),
            careLevel: 'emergency',
            reason: 'no_bed_capacity',
            closureUnit: $closureUnit,
            sourceGroupId: $sourceGroupId,
            durationMinutes: 120,
            eventKey: $eventKey,
            eventType: ClosureEventType::Cluster,
            departmentId: 10,
        );
    }
}
