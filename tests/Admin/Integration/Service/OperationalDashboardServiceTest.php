<?php

declare(strict_types=1);

namespace App\Tests\Admin\Integration\Service;

use App\Admin\Application\DTO\HealthStatusDto;
use App\Admin\Application\Service\OperationalDashboardService;
use App\Shared\Application\Health\HealthCheckStatus;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The Messenger card of the admin dashboard: status from the health report, queue numbers from the live queues.
 * The health items are mapped in HealthCheckItemMapperTest, the card is rendered in DashboardOperationsTest.
 */
#[ResetDatabase]
final class OperationalDashboardServiceTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-11-13 12:30:00 UTC');
    }

    /**
     * @return iterable<string, array{array<string, HealthCheckStatus>, HealthCheckStatus}>
     */
    public static function messengerStatuses(): iterable
    {
        yield 'no Messenger check reported' => [[], HealthCheckStatus::Healthy];
        yield 'failed messages alone' => [['messenger_failed' => HealthCheckStatus::Degraded], HealthCheckStatus::Degraded];
        yield 'the worst Messenger check wins' => [['messenger_backlog' => HealthCheckStatus::Degraded, 'messenger_stalled' => HealthCheckStatus::Unhealthy], HealthCheckStatus::Unhealthy];
        yield 'other checks do not count' => [['database' => HealthCheckStatus::Unhealthy, 'messenger_retries' => HealthCheckStatus::Healthy], HealthCheckStatus::Healthy];
    }

    /**
     * @param array<string, HealthCheckStatus> $statuses
     */
    #[DataProvider('messengerStatuses')]
    public function testTheCardTakesTheWorstMessengerCheckOfTheHealthReport(array $statuses, HealthCheckStatus $expected): void
    {
        $status = $this->service()->getMessengerStatus($this->health($statuses));

        self::assertSame($expected, $status->status);
    }

    public function testEveryQueueButTheFailureQueueCountsItsReadyMessages(): void
    {
        $this->queued('low', '2026-11-13 12:00', '2026-11-13 12:00');
        $this->queued('low', '2026-11-13 12:20', '2026-11-13 12:20');
        $this->queued('high', '2026-11-13 12:25', '2026-11-13 12:25');
        // Waits for a retry until 13:00, so it is not ready.
        $this->queued('mail', '2026-11-13 11:00', '2026-11-13 13:00');
        $this->queued('failed', '2026-11-13 11:30', '2026-11-13 11:30');

        $status = $this->service()->getMessengerStatus($this->health());

        self::assertSame(['high' => 1, 'low' => 2, 'mail' => 0], $status->pendingByQueue);
        self::assertSame(1, $status->failedCount);
        self::assertEquals(new \DateTimeImmutable('2026-11-13 12:00:00 UTC'), $status->oldestPendingAt, 'the message waiting for a retry and the failed one are not the oldest ready ones');
    }

    public function testEmptyQueuesHaveNoOldestMessage(): void
    {
        $status = $this->service()->getMessengerStatus($this->health());

        self::assertSame(['high' => 0, 'low' => 0, 'mail' => 0], $status->pendingByQueue);
        self::assertSame(0, $status->failedCount);
        self::assertNull($status->oldestPendingAt);
    }

    public function testTheCardLinksToTheMessengerPageAndTheFailureQueue(): void
    {
        $status = $this->service()->getMessengerStatus($this->health());

        self::assertStringEndsWith('/admin/operations/messenger', $status->detailUrl);
        self::assertStringEndsWith('/admin/operations/failed-messages', $status->failedMessagesUrl);
    }

    private function service(): OperationalDashboardService
    {
        return self::getContainer()->get(OperationalDashboardService::class);
    }

    /**
     * @param array<string, HealthCheckStatus> $statuses
     */
    private function health(array $statuses = []): HealthStatusDto
    {
        return new HealthStatusDto(HealthCheckStatus::Healthy, '1.0.0', [], [], $statuses);
    }

    /** Doctrine stores the queue's times in UTC without a zone. */
    private function queued(string $queue, string $createdAt, string $availableAt): void
    {
        self::getContainer()->get(Connection::class)->insert('messenger_messages', [
            'body' => 'x',
            'headers' => '{}',
            'queue_name' => $queue,
            'created_at' => $createdAt.':00',
            'available_at' => $availableAt.':00',
        ]);
    }
}
