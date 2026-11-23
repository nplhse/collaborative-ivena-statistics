<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Infrastructure\Notification;

use App\Content\Application\Event\ReactionChanged;
use App\Content\Application\Message\RefreshReactionNotificationMessage;
use App\Content\Domain\Enum\ReactionTargetType;
use App\Content\Domain\Enum\ReactionType;
use App\Content\Infrastructure\Notification\ReactionNotificationSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final class ReactionNotificationSubscriberTest extends TestCase
{
    public function testSchedulesTheRefreshThirtySecondsLater(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (object $message, array $stamps): Envelope {
                self::assertEquals(new RefreshReactionNotificationMessage(ReactionTargetType::Post, 12), $message);
                self::assertCount(1, $stamps);
                self::assertInstanceOf(DelayStamp::class, $stamps[0]);
                self::assertSame(30_000, $stamps[0]->getDelay());

                return new Envelope($message);
            },
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        new ReactionNotificationSubscriber($bus, $logger)->onReactionChanged($this->event());
    }

    public function testAFailedDispatchIsLoggedAndDoesNotReachTheUser(): void
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('transport down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('reaction.notification_schedule_failed', [
            'target_type' => 'post',
            'target_id' => 12,
            'exception' => \RuntimeException::class,
        ]);

        new ReactionNotificationSubscriber($bus, $logger)->onReactionChanged($this->event());
    }

    private function event(): ReactionChanged
    {
        return new ReactionChanged(ReactionTargetType::Post, 12, 3, null, ReactionType::Like);
    }
}
