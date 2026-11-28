<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Wiring of the admin Messenger page; periods, counters, queues and history are tested in
 * MessengerMonitoringServiceTest, the transport list in MessengerMonitoringTransportsTest.
 */
#[ResetDatabase]
final class MessengerMonitoringControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private const string IMPORT = \App\Import\Application\Message\ImportAllocationsMessage::class;
    private const string MAIL = \Symfony\Component\Mailer\Messenger\SendEmailMessage::class;

    public function testShowsEmptyStatesBeforeAnyMessageWasHandled(): void
    {
        $client = $this->adminClient();

        $crawler = $client->request(Request::METHOD_GET, '/admin/operations/messenger');

        self::assertResponseIsSuccessful();
        $text = $crawler->text();
        self::assertStringContainsString('Messenger monitoring', $text);
        self::assertStringContainsString('No message was handled by a worker in this period.', $text);
        self::assertStringContainsString('No failed attempts in this period.', $text);
        self::assertStringContainsString('No message was handled and no queue sample was taken in this period.', $text);
        self::assertCount(0, $crawler->filter('[data-controller="messenger-history-chart"]'));
        self::assertSame(
            ['async_priority_high', 'async_priority_low', 'async_mail', 'scheduler_default', 'failed'],
            $crawler->filter('.ea-messenger-transports tbody tr')->each(static fn ($row): string => (string) $row->attr('data-transport')),
        );
        self::assertStringContainsString('No queue to measure', $crawler->filter('tr[data-transport="scheduler_default"]')->text());
        self::assertGreaterThan(0, $crawler->filter('a[href*="/admin/operations/messenger"]')->count(), 'the System menu links to the page');
    }

    public function testShowsCountersQueuesMessageTypesAndFailuresOfThePeriod(): void
    {
        $client = $this->adminClient();
        $this->metric(self::IMPORT, 'async_priority_high', '2026-11-13 10:00', attempts: 5, handled: 3, handledAfterRetry: 1, retried: 1, failed: 1, durationSum: 5000, durationMax: 2500);
        $this->metric(self::MAIL, 'async_mail', '2026-11-13 09:00', attempts: 10, handled: 10, durationSum: 1000, durationMax: 300);
        $this->failure(self::IMPORT, 'async_priority_high', \Doctrine\DBAL\Exception\DeadlockException::class, '2026-11-13 10:30', willRetry: true);
        $this->queued('high', '2026-11-13 12:10', '2026-11-13 12:10');
        $this->queued('mail', '2026-11-13 12:25', '2026-11-13 12:40');
        $this->queued('failed', '2026-11-12 12:30', '2026-11-12 12:30');

        $crawler = $client->request(Request::METHOD_GET, '/admin/operations/messenger');

        self::assertResponseIsSuccessful();
        self::assertSame('13', trim($crawler->filter('[data-messenger-kpi="handled"]')->text()));
        self::assertSame('1', trim($crawler->filter('[data-messenger-kpi="failed"]')->text()));
        self::assertSame('1', trim($crawler->filter('[data-messenger-kpi="retried"]')->text()));
        self::assertSame('400 ms', trim($crawler->filter('[data-messenger-kpi="duration"]')->text()), '6000 ms over 15 attempts');
        self::assertSame('1', trim($crawler->filter('[data-messenger-kpi="pending"]')->text()));
        $kpis = $crawler->filter('.ea-messenger-kpis')->text();
        self::assertStringContainsString('92,9 %', $kpis, '13 handled of 14 final outcomes');
        self::assertStringContainsString('one waiting for a retry', $kpis);
        self::assertStringContainsString('One message in the failure queue', $kpis);
        $high = $crawler->filter('tr[data-transport="async_priority_high"] td')->each(static fn ($cell): string => trim($cell->text()));
        self::assertSame(['1', '0', '3', '1', '1'], [$high[0], $high[1], $high[3], $high[4], $high[5]]);
        $types = $crawler->filter('.ea-messenger-types tbody tr th span.fw-semibold')->each(static fn ($name): string => trim($name->text()));
        self::assertSame(['SendEmailMessage', 'ImportAllocationsMessage'], $types);
        self::assertStringContainsString('DeadlockException', $crawler->filter('.ea-messenger-failures tbody tr')->text());
        $history = json_decode((string) $crawler->filter('[data-controller="messenger-history-chart"]')->attr('data-messenger-history-chart-chart-value'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($history);
        self::assertSame(13, array_sum($history['handled']), 'the chart gets the history of the period');
    }

    public function testThePeriodIsChosenInTheQuery(): void
    {
        $client = $this->adminClient();
        $this->metric(self::MAIL, 'async_mail', '2026-11-13 09:00', attempts: 10, handled: 10);
        $this->metric(self::MAIL, 'async_mail', '2026-11-10 09:00', attempts: 4, handled: 4);

        $crawler = $client->request(Request::METHOD_GET, '/admin/operations/messenger?period=7d');

        self::assertResponseIsSuccessful();
        self::assertSame('14', trim($crawler->filter('[data-messenger-kpi="handled"]')->text()), 'the older row is inside 7 days');
        self::assertSame('7 days', trim($crawler->filter('[aria-current="true"]')->text()));
    }

    private function metric(string $class, string $transport, string $bucketStart, int $attempts, int $handled, int $handledAfterRetry = 0, int $retried = 0, int $failed = 0, int $durationSum = 0, int $durationMax = 0): void
    {
        $time = new \DateTimeImmutable($bucketStart, new \DateTimeZone('UTC'));
        $this->connection()->insert('messenger_metric', [
            'granularity' => 'hour',
            'bucket_start' => $time->format('Y-m-d H:00:00+00:00'),
            'message_class' => $class,
            'transport' => $transport,
            'attempts' => $attempts,
            'handled' => $handled,
            'handled_after_retry' => $handledAfterRetry,
            'retried' => $retried,
            'failed' => $failed,
            'duration_ms_sum' => $durationSum,
            'duration_ms_max' => $durationMax,
            'last_activity_at' => $time->format('Y-m-d H:i:sP'),
        ]);
    }

    private function failure(string $class, string $transport, string $errorClass, string $at, bool $willRetry): void
    {
        $this->connection()->insert('messenger_failure', [
            'occurred_at' => $at.':00+00:00',
            'message_class' => $class,
            'transport' => $transport,
            'attempt' => 1,
            'will_retry' => $willRetry,
            'error_class' => $errorClass,
        ], ['will_retry' => 'boolean']);
    }

    /** Doctrine stores the queue's times in UTC without a zone. */
    private function queued(string $queue, string $createdAt, string $availableAt): void
    {
        $this->connection()->insert('messenger_messages', [
            'body' => 'O:8:"stdClass":0:{}',
            'headers' => '{}',
            'queue_name' => $queue,
            'created_at' => $createdAt.':00',
            'available_at' => $availableAt.':00',
        ]);
    }

    private function adminClient(): KernelBrowser
    {
        $client = self::createClient();
        self::mockTime('2026-11-13 12:30:00 UTC');
        $client->loginUser(UserFactory::new()->asAdmin()->create());

        return $client;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
