<?php

declare(strict_types=1);

namespace App\Tests\Feedback\Unit\Domain;

use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackClassification;
use App\Feedback\Domain\Enum\FeedbackStatus;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

final class FeedbackClassificationTest extends TestCase
{
    public function testNewFeedbackIsNormalAndUnclassified(): void
    {
        $feedback = new Feedback();

        self::assertSame(FeedbackClassification::NORMAL, $feedback->getClassification());
        self::assertFalse($feedback->isQuarantined());
        self::assertSame([], $feedback->getClassificationReasons());
        self::assertNull($feedback->getSpamScore());
        self::assertNull($feedback->getClassifiedAt());
        self::assertNull($feedback->getClassifiedBy());
        self::assertNull($feedback->getQuarantineReportedAt());
    }

    public function testAutomaticQuarantineKeepsReasonsAndWaitsForTheSummary(): void
    {
        $at = new \DateTimeImmutable('2026-11-10 08:00:00');
        $feedback = new Feedback()->quarantine(['contains_url', 'missing_page_context', 'contains_url'], 8, null, $at);

        self::assertTrue($feedback->isQuarantined());
        self::assertSame(['contains_url', 'missing_page_context'], $feedback->getClassificationReasons(), 'reasons are de-duplicated');
        self::assertSame(8, $feedback->getSpamScore());
        self::assertSame($at, $feedback->getClassifiedAt());
        self::assertNull($feedback->getClassifiedBy());
        self::assertNull($feedback->getQuarantineReportedAt(), 'an automatic quarantine is reported in the next summary');
        self::assertSame(FeedbackStatus::NEW, $feedback->getStatus(), 'the workflow status is not touched');
    }

    public function testManualQuarantineIsNotReportedAgain(): void
    {
        $reviewer = new User();
        $at = new \DateTimeImmutable('2026-11-10 09:30:00');
        $feedback = new Feedback()->setStatus(FeedbackStatus::DONE)->quarantine(['manual'], null, $reviewer, $at);

        self::assertTrue($feedback->isQuarantined());
        self::assertSame(['manual'], $feedback->getClassificationReasons());
        self::assertNull($feedback->getSpamScore());
        self::assertSame($reviewer, $feedback->getClassifiedBy());
        self::assertSame($at, $feedback->getQuarantineReportedAt(), 'the reviewer knows about it already');
        self::assertSame(FeedbackStatus::DONE, $feedback->getStatus());
    }

    public function testReleaseDropsReasonsAndRecordsTheReviewer(): void
    {
        $reviewer = new User();
        $quarantinedAt = new \DateTimeImmutable('2026-11-10 08:00:00');
        $releasedAt = new \DateTimeImmutable('2026-11-11 10:00:00');
        $feedback = new Feedback()
            ->quarantine(['contains_url'], 6, null, $quarantinedAt)
            ->release($reviewer, $releasedAt);

        self::assertSame(FeedbackClassification::NORMAL, $feedback->getClassification());
        self::assertSame([], $feedback->getClassificationReasons());
        self::assertNull($feedback->getSpamScore());
        self::assertSame($releasedAt, $feedback->getClassifiedAt());
        self::assertSame($reviewer, $feedback->getClassifiedBy());
        self::assertSame(FeedbackStatus::NEW, $feedback->getStatus());
    }

    public function testQuarantiningTwiceIsRejected(): void
    {
        $feedback = new Feedback()->quarantine(['manual'], null, new User(), new \DateTimeImmutable('2026-11-10 08:00:00'));

        $this->expectException(\LogicException::class);
        $feedback->quarantine(['manual'], null, new User(), new \DateTimeImmutable('2026-11-10 08:05:00'));
    }

    public function testReleasingNormalFeedbackIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        new Feedback()->release(new User(), new \DateTimeImmutable('2026-11-10 08:00:00'));
    }
}
