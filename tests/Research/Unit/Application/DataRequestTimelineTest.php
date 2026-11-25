<?php

declare(strict_types=1);

namespace App\Tests\Research\Unit\Application;

use App\Research\Application\DataRequest\DataRequestTimeline;
use App\Research\Application\DataRequest\TimelineEntry;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Enum\TimelineEventType;
use App\Research\Domain\Enum\Visibility;
use App\Research\Domain\ValueObject\DataRequestContent;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Events that belong together become one timeline entry (ADR 045): the same person, the same visibility, at most ten
 * minutes apart, not separated by a comment.
 */
final class DataRequestTimelineTest extends TestCase
{
    public function testRelatedEventsAreGrouped(): void
    {
        $admin = $this->user(1);
        $applicant = $this->user(2);
        $request = new DataRequest($applicant, $this->content(), new \DateTimeImmutable('2026-12-01 09:00'));
        $all = [Visibility::Internal, Visibility::Private, Visibility::Public];

        $request->recordEvent(TimelineEventType::QuerySaved, $admin, Visibility::Public, new \DateTimeImmutable('2026-12-01 19:34'), ['query' => 'a', 'version' => 1]);
        $request->recordEvent(TimelineEventType::QuerySaved, $admin, Visibility::Public, new \DateTimeImmutable('2026-12-01 19:35'), ['query' => 'b', 'version' => 1]);
        // 10 minutes after the previous one: still the same entry.
        $request->recordEvent(TimelineEventType::QuerySaved, $admin, Visibility::Public, new \DateTimeImmutable('2026-12-01 19:45'), ['query' => 'c', 'version' => 1]);
        // Another visibility: a new entry.
        $request->recordEvent(TimelineEventType::Released, $admin, Visibility::Private, new \DateTimeImmutable('2026-12-01 19:46'), ['release' => 1]);
        // Another person: a new entry.
        $request->recordEvent(TimelineEventType::ExportRequested, $applicant, Visibility::Private, new \DateTimeImmutable('2026-12-01 19:47'), ['release' => 1]);
        // More than 10 minutes later: a new entry.
        $request->recordEvent(TimelineEventType::ExportRequested, $applicant, Visibility::Private, new \DateTimeImmutable('2026-12-01 19:58'), ['release' => 1]);
        // A comment between two events of the same person ends the group.
        $request->addComment($applicant, 'Thanks.', Visibility::Public, null, new \DateTimeImmutable('2026-12-01 19:59'));
        $request->recordEvent(TimelineEventType::ExportRequested, $applicant, Visibility::Private, new \DateTimeImmutable('2026-12-01 20:00'), ['release' => 1]);

        $entries = DataRequestTimeline::build($request, $all);

        self::assertSame([3, 1, 1, 1, 0, 1], array_map(static fn (TimelineEntry $entry): int => \count($entry->events), $entries));
        self::assertSame(['a', 'b', 'c'], array_map(static fn (\App\Research\Domain\Entity\DataRequestEvent $event): int|string => $event->getDetails()['query'], $entries[0]->events));
        self::assertSame($entries[0]->events[2], $entries[0]->lastEvent());
        self::assertNull($entries[4]->lastEvent(), 'a comment has no events');

        $public = DataRequestTimeline::build($request, [Visibility::Public]);
        self::assertSame([3, 0], array_map(static fn (TimelineEntry $entry): int => \count($entry->events), $public), 'only the levels the reader may read');
    }

    private function user(int $id): User
    {
        $user = new User();
        new \ReflectionProperty(User::class, 'id')->setValue($user, $id);

        return $user;
    }

    private function content(): DataRequestContent
    {
        return new DataRequestContent(
            title: 'Title',
            summary: 'Summary',
            institution: 'University',
            enrichmentPlanned: false,
            purpose: 'Purpose',
            periodFrom: new \DateTimeImmutable('2024-01-01'),
            periodTo: new \DateTimeImmutable('2024-12-31'),
            population: 'All allocations',
            variables: 'Month',
            granularity: 'Month',
            recipients: 'Team',
            enrichmentDetails: null,
            singleCases: false,
            description: 'Description',
            dataNeeds: 'Data needs',
            responsiblePersons: 'Dr. A',
            conflictsOfInterest: 'none',
            ethics: 'Not needed',
        );
    }
}
