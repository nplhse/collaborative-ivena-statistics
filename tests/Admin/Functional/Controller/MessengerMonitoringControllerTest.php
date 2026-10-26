<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Admin\Application\Service\Messenger\MessengerMonitoringService;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class MessengerMonitoringControllerTest extends WebTestCase
{
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

    public function testShowsCountersQueuesAndFailuresOfThePeriod(): void
    {
        $client = $this->adminClient();
        $this->metric(self::IMPORT, 'async_priority_high', '-2 hours', attempts: 5, handled: 3, handledAfterRetry: 1, retried: 1, failed: 1, durationSum: 5000, durationMax: 2500);
        $this->metric(self::MAIL, 'async_mail', '-3 hours', attempts: 10, handled: 10, durationSum: 1000, durationMax: 300);
        $this->metric(self::MAIL, 'async_mail', '-3 days', attempts: 4, handled: 4, durationSum: 400, durationMax: 100);
        $this->failure(self::IMPORT, 'async_priority_high', \Doctrine\DBAL\Exception\DeadlockException::class, '-2 hours', willRetry: true);
        $this->failure(self::IMPORT, 'async_priority_high', \Doctrine\DBAL\Exception\DeadlockException::class, '-90 minutes', willRetry: false);
        $this->queued('high', '-20 minutes', '-20 minutes');
        $this->queued('mail', '-5 minutes', '+10 minutes');
        $this->queued('failed', '-1 day', '-1 day');
        $sampledAt = new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC'))->format('Y-m-d H:00:00+00:00');
        $this->connection()->executeStatement('INSERT INTO messenger_queue_sample (sampled_at, pending, waiting, failed) VALUES (?, 7, 0, 1)', [$sampledAt]);

        $crawler = $client->request(Request::METHOD_GET, '/admin/operations/messenger');

        self::assertResponseIsSuccessful();
        self::assertSame('13', trim($crawler->filter('[data-messenger-kpi="handled"]')->text()));
        self::assertSame('1', trim($crawler->filter('[data-messenger-kpi="failed"]')->text()));
        self::assertSame('1', trim($crawler->filter('[data-messenger-kpi="retried"]')->text()));
        self::assertSame('400 ms', trim($crawler->filter('[data-messenger-kpi="duration"]')->text()), '6000 ms over 15 attempts');
        self::assertSame('1', trim($crawler->filter('[data-messenger-kpi="pending"]')->text()), 'the failure queue and delayed messages are not pending');
        $kpis = $crawler->filter('.ea-messenger-kpis')->text();
        self::assertStringContainsString('92,9 %', $kpis, '13 handled of 14 final outcomes');
        self::assertStringContainsString('one waiting for a retry', $kpis);
        self::assertStringContainsString('One message in the failure queue', $kpis);

        $high = $crawler->filter('tr[data-transport="async_priority_high"] td')->each(static fn ($cell): string => trim($cell->text()));
        self::assertSame(['1', '0'], [$high[0], $high[1]]);
        self::assertSame(['3', '1', '1'], [$high[3], $high[4], $high[5]]);

        $types = $crawler->filter('.ea-messenger-types tbody tr th span.fw-semibold')->each(static fn ($name): string => trim($name->text()));
        self::assertSame(['SendEmailMessage', 'ImportAllocationsMessage'], $types, 'most attempts first');

        $chart = $crawler->filter('[data-controller="messenger-history-chart"]');
        self::assertCount(1, $chart);
        $history = json_decode((string) $chart->attr('data-messenger-history-chart-chart-value'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($history);
        self::assertSame('hour', $history['granularity']);
        self::assertCount(25, $history['buckets'], 'every hour from the start of the period to the current one');
        self::assertSame(13, array_sum($history['handled']));
        self::assertSame(1, array_sum($history['failed']));
        self::assertSame(1, array_sum($history['retried']));
        self::assertSame([7], array_values(array_filter($history['backlog'], static fn ($value): bool => null !== $value)), 'one hourly sample');
        self::assertSame($sampledAt, new \DateTimeImmutable($history['buckets'][array_search(7, $history['backlog'], true)])->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:00:00+00:00'));

        $failure = $crawler->filter('.ea-messenger-failures tbody tr')->each(static fn ($cell): string => trim($cell->text()));
        self::assertCount(1, $failure);
        self::assertStringContainsString('DeadlockException', $failure[0]);

        $crawler = $client->request(Request::METHOD_GET, '/admin/operations/messenger?period=7d');

        self::assertResponseIsSuccessful();
        self::assertSame('17', trim($crawler->filter('[data-messenger-kpi="handled"]')->text()), 'the older mail row is inside 7 days');
        self::assertSame('7 days', trim($crawler->filter('[aria-current="true"]')->text()));

        $crawler = $client->request(Request::METHOD_GET, '/admin/operations/messenger?period=30d');

        self::assertResponseIsSuccessful();
        $history = json_decode((string) $crawler->filter('[data-controller="messenger-history-chart"]')->attr('data-messenger-history-chart-chart-value'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($history);
        self::assertSame('day', $history['granularity']);
        self::assertGreaterThanOrEqual(30, \count($history['buckets']));
        self::assertLessThanOrEqual(31, \count($history['buckets']));
        self::assertSame(17, array_sum($history['handled']), 'daily points sum the hourly rows');
        self::assertSame('00:00', new \DateTimeImmutable($history['buckets'][0])->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('H:i'), 'days start at midnight in Berlin');
    }

    public function testTheTransportListMatchesTheMessengerConfiguration(): void
    {
        /** @var array{framework: array{messenger: array{transports: array<string, mixed>}}} $config */
        $config = Yaml::parseFile(\dirname(__DIR__, 4).'/config/packages/messenger.yaml');
        $queues = [];
        foreach ($config['framework']['messenger']['transports'] as $name => $transport) {
            if ('sync' === $name) {
                continue;
            }
            $queues[$name] = \is_array($transport) ? ($transport['options']['queue_name'] ?? null) : 'failed';
        }
        $queues['scheduler_default'] = null;

        self::assertEqualsCanonicalizing($queues, MessengerMonitoringService::TRANSPORTS);
    }

    private function metric(string $class, string $transport, string $at, int $attempts, int $handled, int $handledAfterRetry = 0, int $retried = 0, int $failed = 0, int $durationSum = 0, int $durationMax = 0): void
    {
        $time = new \DateTimeImmutable($at, new \DateTimeZone('UTC'));
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
            'occurred_at' => new \DateTimeImmutable($at, new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP'),
            'message_class' => $class,
            'transport' => $transport,
            'attempt' => 1,
            'will_retry' => $willRetry,
            'error_class' => $errorClass,
        ], ['will_retry' => 'boolean']);
    }

    private function queued(string $queue, string $createdAt, string $availableAt): void
    {
        $utc = new \DateTimeZone('UTC');
        $this->connection()->insert('messenger_messages', [
            'body' => 'O:8:"stdClass":0:{}',
            'headers' => '{}',
            'queue_name' => $queue,
            'created_at' => new \DateTimeImmutable($createdAt, $utc)->format('Y-m-d H:i:s'),
            'available_at' => new \DateTimeImmutable($availableAt, $utc)->format('Y-m-d H:i:s'),
        ]);
    }

    private function adminClient(): KernelBrowser
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create());

        return $client;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
