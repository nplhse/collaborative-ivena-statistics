<?php

declare(strict_types=1);

namespace App\Tests\Research\Unit\Domain\Entity;

use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Enum\DataRequestStatus;
use App\Research\Domain\Exception\DataRequestException;
use App\Research\Domain\ValueObject\DataRequestContent;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * A data extract request keeps its versions frozen once submitted (ADR 045).
 */
final class DataRequestTest extends TestCase
{
    public function testANewRequestIsADraftWithOneVersion(): void
    {
        $request = new DataRequest(new User(), $this->content('Stroke pathways'), new \DateTimeImmutable('2026-11-25 10:00'));

        self::assertSame(DataRequestStatus::Draft, $request->getStatus());
        self::assertSame('draft', $request->getMarking());
        self::assertCount(1, $request->getVersions());
        self::assertSame(1, $request->getCurrentVersion()->getNumber());
        self::assertSame('Stroke pathways', $request->getCurrentVersion()->getTitle());
        self::assertFalse($request->wasSubmitted());
    }

    public function testADraftVersionCanChangeUntilItIsSubmitted(): void
    {
        $request = new DataRequest(new User(), $this->content('First title'), new \DateTimeImmutable('2026-11-25 10:00'));

        $request->updateCurrentVersion($this->content('Second title'), new \DateTimeImmutable('2026-11-25 11:00'));
        self::assertSame('Second title', $request->getCurrentVersion()->getTitle());
        self::assertEquals(new \DateTimeImmutable('2026-11-25 11:00'), $request->getCurrentVersion()->getUpdatedAt());

        $request->setMarking('submitted');
        $request->recordSubmission(new \DateTimeImmutable('2026-11-25 12:00'));
        self::assertTrue($request->wasSubmitted());
        self::assertTrue($request->getCurrentVersion()->isSubmitted());

        $this->expectException(DataRequestException::class);
        $request->updateCurrentVersion($this->content('Third title'), new \DateTimeImmutable('2026-11-25 13:00'));
    }

    public function testTheFirstSubmissionTimeStays(): void
    {
        $request = new DataRequest(new User(), $this->content('Title'), new \DateTimeImmutable('2026-11-25 10:00'));

        $request->recordSubmission(new \DateTimeImmutable('2026-11-25 12:00'));
        $request->recordSubmission(new \DateTimeImmutable('2026-11-26 12:00'));

        self::assertEquals(new \DateTimeImmutable('2026-11-25 12:00'), $request->getSubmittedAt());
    }

    public function testEnrichmentDetailsAreDroppedWithoutAPlannedEnrichment(): void
    {
        $content = $this->content('Title', enrichmentPlanned: false, enrichmentDetails: 'Local registry');
        $request = new DataRequest(new User(), $content, new \DateTimeImmutable('2026-11-25 10:00'));

        self::assertNull($request->getCurrentVersion()->getEnrichmentDetails());
    }

    public function testAPeriodThatEndsBeforeItStartsIsRefused(): void
    {
        $this->expectException(DataRequestException::class);

        new DataRequest(new User(), $this->content('Title', periodFrom: '2025-01-01', periodTo: '2024-12-31'), new \DateTimeImmutable('2026-11-25 10:00'));
    }

    private function content(
        string $title,
        bool $enrichmentPlanned = false,
        ?string $enrichmentDetails = null,
        string $periodFrom = '2024-01-01',
        string $periodTo = '2024-12-31',
    ): DataRequestContent {
        return new DataRequestContent(
            title: $title,
            summary: 'Summary',
            institution: 'University',
            enrichmentPlanned: $enrichmentPlanned,
            purpose: 'Purpose',
            periodFrom: new \DateTimeImmutable($periodFrom),
            periodTo: new \DateTimeImmutable($periodTo),
            population: 'All allocations',
            variables: 'Age, PZC',
            granularity: 'Month and hospital group',
            recipients: 'Research team',
            enrichmentDetails: $enrichmentDetails,
            singleCases: false,
            description: 'Description',
            dataNeeds: 'Data needs',
            responsiblePersons: 'Dr. A',
            conflictsOfInterest: 'none',
            ethics: 'Not needed',
        );
    }
}
