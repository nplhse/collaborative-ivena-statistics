<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Infrastructure\Notification;

use App\Content\Application\Event\MediaIntegrityChecked;
use App\Content\Application\Media\MediaIntegrityReport;
use App\Content\Infrastructure\Notification\AdminMediaIntegrityNotification;
use App\Content\Infrastructure\Notification\MediaIntegrityNotificationSubscriber;
use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationRequest;
use App\User\Application\Contract\AdminNotificationRecipientResolverInterface;
use PHPUnit\Framework\TestCase;

final class MediaIntegrityNotificationSubscriberTest extends TestCase
{
    /** @var list<NotificationRequest> */
    private array $published = [];

    public function testMissingAndDamagedFilesAreReportedWithTheirCounts(): void
    {
        $this->notify(missing: ['originals/2026/11/a.png'], damaged: ['originals/2026/11/b.png', 'originals/2026/11/c.png']);

        self::assertCount(1, $this->published);
        self::assertSame(AdminMediaIntegrityNotification::TYPE, $this->published[0]->type);
        self::assertSame([7, 9], $this->published[0]->recipientUserIds);
        self::assertSame(['missing' => 1, 'damaged' => 2], $this->published[0]->parameters);
    }

    public function testTheSameProblemsGetTheSameKeyAndNewProblemsANewOne(): void
    {
        $this->notify(missing: ['originals/2026/11/a.png', 'originals/2026/11/b.png'], damaged: []);
        $this->notify(missing: ['originals/2026/11/b.png', 'originals/2026/11/a.png'], damaged: []);
        $this->notify(missing: ['originals/2026/11/a.png'], damaged: []);

        self::assertSame($this->published[0]->deduplicationKey, $this->published[1]->deduplicationKey, 'the order of the files does not matter');
        self::assertNotSame($this->published[0]->deduplicationKey, $this->published[2]->deduplicationKey);
    }

    public function testARunWithoutProblemsSendsNothing(): void
    {
        $this->notify(missing: [], damaged: []);

        self::assertSame([], $this->published);
    }

    /**
     * @param list<string> $missing
     * @param list<string> $damaged
     */
    private function notify(array $missing, array $damaged): void
    {
        $recipients = $this->createStub(AdminNotificationRecipientResolverInterface::class);
        $recipients->method('recipientIds')->willReturn([7, 9]);
        $publisher = $this->createStub(NotificationPublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(function (NotificationRequest $request): void {
            $this->published[] = $request;
        });

        new MediaIntegrityNotificationSubscriber($recipients, $publisher)->onChecked(new MediaIntegrityChecked(new MediaIntegrityReport(
            new \DateTimeImmutable('2026-11-21 05:10'),
            false,
            3,
            300,
            3,
            $missing,
            $damaged,
            [],
            [],
            0,
            [],
            null,
        )));
    }
}
