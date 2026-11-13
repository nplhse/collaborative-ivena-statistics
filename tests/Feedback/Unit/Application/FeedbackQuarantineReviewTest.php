<?php

declare(strict_types=1);

namespace App\Tests\Feedback\Unit\Application;

use App\Feedback\Application\FeedbackQuarantineReview;
use App\Feedback\Domain\Entity\Feedback;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class FeedbackQuarantineReviewTest extends TestCase
{
    private const string NOW = '2026-11-13 10:00:00';

    public function testReleaseSkipsEntriesThatAreNotQuarantined(): void
    {
        $quarantined = new Feedback()->quarantine(['token_only_message'], 7, null, new \DateTimeImmutable('2026-11-10 08:00:00'));
        $normal = new Feedback();
        $reviewer = new User();

        $released = $this->review()->release([$quarantined, $normal], $reviewer);

        self::assertSame(1, $released);
        self::assertFalse($quarantined->isQuarantined());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $quarantined->getClassifiedAt());
        self::assertNull($normal->getClassifiedAt(), 'a normal entry is left untouched');
    }

    public function testDeleteRemovesQuarantinedEntriesOnly(): void
    {
        $quarantined = new Feedback()->quarantine(['spam_pattern'], 6, null, new \DateTimeImmutable('2026-11-10 08:00:00'));
        $normal = new Feedback();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('remove')->with($quarantined);

        self::assertSame(1, $this->review($entityManager)->delete([$normal, $quarantined]));
    }

    public function testQuarantiningAQuarantinedEntryChangesNothing(): void
    {
        $at = new \DateTimeImmutable('2026-11-10 08:00:00');
        $feedback = new Feedback()->quarantine(['missing_page_context', 'duplicate_message'], 7, null, $at);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $this->review($entityManager)->quarantine($feedback, new User());

        self::assertSame(['missing_page_context', 'duplicate_message'], $feedback->getClassificationReasons());
        self::assertSame(7, $feedback->getSpamScore());
        self::assertSame($at, $feedback->getClassifiedAt());
        self::assertNull($feedback->getClassifiedBy());
    }

    private function review(?EntityManagerInterface $entityManager = null): FeedbackQuarantineReview
    {
        return new FeedbackQuarantineReview(
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            new MockClock(self::NOW),
        );
    }
}
