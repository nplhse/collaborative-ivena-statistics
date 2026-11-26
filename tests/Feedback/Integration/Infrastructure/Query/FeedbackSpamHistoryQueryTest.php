<?php

declare(strict_types=1);

namespace App\Tests\Feedback\Integration\Infrastructure\Query;

use App\Feedback\Application\FeedbackMessageFingerprint;
use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackCategory;
use App\Feedback\Infrastructure\Query\FeedbackSpamHistoryQuery;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class FeedbackSpamHistoryQueryTest extends DatabaseKernelTestCase
{
    use ClockSensitiveTrait;

    public function testCountsGuestEmailAndMessageWithinThirtyDays(): void
    {
        $message = 'Hola, quería saber tu precio.';

        self::mockTime('2026-10-01 12:00:00');
        $this->store('Spammer@Example.test', $message); // 31 days before the lookup: outside
        self::mockTime('2026-10-02 12:00:00');
        $this->store('spammer@example.test', 'Something else'); // exactly 30 days: inside
        self::mockTime('2026-10-20 08:00:00');
        $this->store('other@example.test', $message, quarantined: true);
        $this->store(null, $message);

        self::mockTime('2026-11-01 12:00:00');
        $history = self::getContainer()->get(FeedbackSpamHistoryQuery::class)
            ->lookup(' SPAMMER@example.test ', FeedbackMessageFingerprint::fromMessage($message));

        self::assertSame(1, $history->earlierEntriesFromGuestEmail, 'case-insensitive, 30-day window');
        self::assertSame(2, $history->earlierEntriesWithSameMessage, 'normal and quarantined entries count');
    }

    public function testNoGuestEmailOrFingerprintCountsNothing(): void
    {
        $this->store(null, 'Some message');

        $history = self::getContainer()->get(FeedbackSpamHistoryQuery::class)->lookup(null, null);

        self::assertSame(0, $history->earlierEntriesFromGuestEmail);
        self::assertSame(0, $history->earlierEntriesWithSameMessage);
    }

    private function store(?string $guestEmail, string $message, bool $quarantined = false): void
    {
        $feedback = new Feedback()
            ->setCategory(FeedbackCategory::BUG)
            ->setMessage($message)
            ->setGuestEmail($guestEmail)
            ->setPageUrl('https://example.test/')
            ->setMessageFingerprint(FeedbackMessageFingerprint::fromMessage($message));
        if ($quarantined) {
            $feedback->quarantine(['missing_page_context', 'duplicate_message'], 7, null, new \DateTimeImmutable('2026-10-20 08:00:00'));
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($feedback);
        $entityManager->flush();
    }
}
