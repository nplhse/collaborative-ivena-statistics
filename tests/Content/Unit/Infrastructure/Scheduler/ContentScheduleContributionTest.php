<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Infrastructure\Scheduler;

use App\Admin\Application\Service\JobRun\JobRunCatalog;
use App\Content\Application\Message\CheckMediaIntegrityMessage;
use App\Content\Infrastructure\Scheduler\ContentScheduleContribution;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;

final class ContentScheduleContributionTest extends TestCase
{
    public function testChecksTheMediaDailyAtTenPastFiveInBerlin(): void
    {
        $messages = new ContentScheduleContribution()->recurringMessages();
        self::assertCount(1, $messages);

        $trigger = $messages[0]->getTrigger();
        self::assertSame(
            '2026-11-22 05:10 +01:00',
            $trigger->getNextRunDate(new \DateTimeImmutable('2026-11-21 12:00', new \DateTimeZone('Europe/Berlin')))?->format('Y-m-d H:i P'),
        );

        $context = new MessageContext('default', $messages[0]->getId(), $trigger, new \DateTimeImmutable('2026-11-21 12:00'));
        $scheduled = [...$messages[0]->getMessages($context)];
        self::assertCount(1, $scheduled);
        self::assertInstanceOf(CheckMediaIntegrityMessage::class, $scheduled[0]);
    }

    public function testTheJobIsRecordedUnderTheNameTheAdminCatalogKnows(): void
    {
        self::assertSame([CheckMediaIntegrityMessage::class => JobRunCatalog::JOB_MEDIA_INTEGRITY], new ContentScheduleContribution()->recordedJobs());
        self::assertContains(ContentScheduleContribution::JOB_MEDIA_INTEGRITY, JobRunCatalog::JOBS);
    }
}
