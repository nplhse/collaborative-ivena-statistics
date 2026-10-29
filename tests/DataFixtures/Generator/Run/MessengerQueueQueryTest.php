<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator\Run;

use App\DataFixtures\Generator\Infrastructure\Query\MessengerQueueQuery;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Worker detection of the wizard's summary (#791). Now is 2026-10-29 10:00:00 UTC.
 */
#[ResetDatabase]
final class MessengerQueueQueryTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime(new \DateTimeImmutable('2026-10-29 10:00:00', new \DateTimeZone('UTC')));
    }

    public function testAnEmptyQueueMeansAnIdleWorker(): void
    {
        $state = $this->query()->workerState();

        self::assertTrue($state->known);
        self::assertTrue($state->isIdle());
        self::assertFalse($state->seemsMissing());
    }

    public function testAMessageWaitingNinetySecondsWithNothingInProgressMeansNoWorker(): void
    {
        $this->message('low', availableAt: '2026-10-29 09:58:30');
        $this->message('high', availableAt: '2026-10-29 09:59:50');
        $this->message('low', availableAt: '2026-10-29 10:05:00'); // delayed retry, not waiting yet
        $this->message('mail', availableAt: '2026-10-29 09:00:00'); // another queue

        $state = $this->query()->workerState();

        self::assertSame([2, 0, 90], [$state->waiting, $state->inProgress, $state->oldestWaitingSeconds]);
        self::assertTrue($state->seemsMissing());
    }

    public function testAMessageInProgressMeansABusyWorker(): void
    {
        $this->message('low', availableAt: '2026-10-29 09:50:00', deliveredAt: '2026-10-29 09:55:00');
        $this->message('low', availableAt: '2026-10-29 09:51:00');

        $state = $this->query()->workerState();

        self::assertSame([1, 1, 540], [$state->waiting, $state->inProgress, $state->oldestWaitingSeconds]);
        self::assertTrue($state->isBusy());
        self::assertFalse($state->seemsMissing(), 'a long job keeps the others waiting');
    }

    public function testAMessageWaitingLessThanAMinuteIsNotYetSuspicious(): void
    {
        $this->message('low', availableAt: '2026-10-29 09:59:30');

        $state = $this->query()->workerState();

        self::assertSame(30, $state->oldestWaitingSeconds);
        self::assertFalse($state->seemsMissing());
        self::assertFalse($state->isIdle());
    }

    private function message(string $queue, string $availableAt, ?string $deliveredAt = null): void
    {
        self::getContainer()->get(Connection::class)->insert('messenger_messages', [
            'body' => 'x',
            'headers' => '[]',
            'queue_name' => $queue,
            'created_at' => $availableAt,
            'available_at' => $availableAt,
            'delivered_at' => $deliveredAt,
        ]);
    }

    private function query(): MessengerQueueQuery
    {
        return new MessengerQueueQuery(self::getContainer()->get(Connection::class), Clock::get());
    }
}
