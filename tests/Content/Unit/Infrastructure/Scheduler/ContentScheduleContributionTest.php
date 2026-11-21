<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Infrastructure\Scheduler;

use App\Admin\Application\Service\JobRun\JobRunCatalog;
use App\Content\Application\Message\CheckMediaIntegrityMessage;
use App\Content\Application\Message\CleanUpReactionsMessage;
use App\Content\Infrastructure\Scheduler\ContentScheduleContribution;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;

final class ContentScheduleContributionTest extends TestCase
{
    public function testChecksTheMediaDailyAtTenPastFiveInBerlin(): void
    {
        $messages = new ContentScheduleContribution()->recurringMessages();
        self::assertCount(2, $messages);

        self::assertSame('2026-11-22 05:10 +01:00', $this->nextRun($messages[0]));
        self::assertInstanceOf(CheckMediaIntegrityMessage::class, $this->scheduled($messages[0]));
    }

    public function testCleansUpReactionsDailyAtTwentyToSixInBerlin(): void
    {
        $messages = new ContentScheduleContribution()->recurringMessages();

        self::assertSame('2026-11-22 05:40 +01:00', $this->nextRun($messages[1]));
        self::assertInstanceOf(CleanUpReactionsMessage::class, $this->scheduled($messages[1]));
    }

    public function testTheJobsAreRecordedUnderTheNamesTheAdminCatalogKnows(): void
    {
        self::assertSame([
            CheckMediaIntegrityMessage::class => JobRunCatalog::JOB_MEDIA_INTEGRITY,
            CleanUpReactionsMessage::class => JobRunCatalog::JOB_REACTION_CLEANUP,
        ], new ContentScheduleContribution()->recordedJobs());
        self::assertContains(ContentScheduleContribution::JOB_MEDIA_INTEGRITY, JobRunCatalog::JOBS);
        self::assertContains(ContentScheduleContribution::JOB_REACTION_CLEANUP, JobRunCatalog::JOBS);
    }

    private function nextRun(RecurringMessage $message): ?string
    {
        return $message->getTrigger()
            ->getNextRunDate(new \DateTimeImmutable('2026-11-21 12:00', new \DateTimeZone('Europe/Berlin')))
            ?->format('Y-m-d H:i P');
    }

    private function scheduled(RecurringMessage $message): object
    {
        $context = new MessageContext('default', $message->getId(), $message->getTrigger(), new \DateTimeImmutable('2026-11-21 12:00'));
        $scheduled = [...$message->getMessages($context)];
        self::assertCount(1, $scheduled);

        return $scheduled[0];
    }
}
