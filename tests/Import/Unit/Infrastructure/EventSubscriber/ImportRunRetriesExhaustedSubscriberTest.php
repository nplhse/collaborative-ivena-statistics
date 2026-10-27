<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Infrastructure\EventSubscriber;

use App\Import\Application\Contracts\ImportBatchSummaryPublisherInterface;
use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Application\Service\ImportBatchCompletion;
use App\Import\Application\Service\ImportMilestoneRecorder;
use App\Import\Application\Service\ImportRunLifecycle;
use App\Import\Infrastructure\EventSubscriber\ImportRunRetriesExhaustedSubscriber;
use App\Import\Infrastructure\Monitoring\ImportRunTelemetry;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\Shared\Infrastructure\Monitoring\JobRun\JobRunRecorder;
use App\Shared\Infrastructure\Monitoring\JobRun\JobRunThresholdConfig;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sentry\State\HubInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Uid\Uuid;

final class ImportRunRetriesExhaustedSubscriberTest extends TestCase
{
    public function testIgnoresFailuresThatWillBeRetried(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects($this->never())->method('getManager');

        $event = $this->failedEvent(new ImportAllocationsMessage(1, Uuid::v7()->toRfc4122()));
        $event->setForRetry();

        $this->subscriber($registry, $this->createStub(LoggerInterface::class))->onMessageFailed($event);
    }

    public function testIgnoresOtherAndLegacyMessages(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects($this->never())->method('getManager');
        $subscriber = $this->subscriber($registry, $this->createStub(LoggerInterface::class));

        $subscriber->onMessageFailed($this->failedEvent(new \stdClass()));
        $subscriber->onMessageFailed($this->failedEvent(new ImportAllocationsMessage(1)));
    }

    public function testErrorsWhileEndingTheRunAreLoggedAndDoNotBreakTheWorker(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willThrowException(new \RuntimeException('database gone'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('critical')
            ->with('import.run.retries_exhausted_not_recorded', $this->callback(
                static fn (array $context): bool => 7 === $context['id'] && 'database gone' === $context['msg'],
            ));

        $this->subscriber($registry, $logger)
            ->onMessageFailed($this->failedEvent(new ImportAllocationsMessage(7, Uuid::v7()->toRfc4122())));
    }

    private function subscriber(ManagerRegistry $registry, LoggerInterface $logger): ImportRunRetriesExhaustedSubscriber
    {
        return new ImportRunRetriesExhaustedSubscriber(
            new ImportRunLifecycle($registry, new EventDispatcher(), new AuditContext(), $logger, new ImportMilestoneRecorder(), new ImportBatchCompletion($this->createStub(Connection::class), $this->createStub(ImportBatchSummaryPublisherInterface::class), $logger, new NativeClock()), new NativeClock()),
            $logger,
            new ImportRunTelemetry(
                new JobRunRecorder($this->createStub(Connection::class), new MockClock(), $logger),
                new JobRunThresholdConfig([]),
                $this->createStub(HubInterface::class),
                $this->createStub(Connection::class),
                $logger,
            ),
        );
    }

    private function failedEvent(object $message): WorkerMessageFailedEvent
    {
        return new WorkerMessageFailedEvent(new Envelope($message), 'async_priority_high', new \RuntimeException('handler failed'));
    }
}
