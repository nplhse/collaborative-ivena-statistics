<?php

declare(strict_types=1);

namespace App\Admin\Application\Service\Messenger;

use App\Admin\Application\DTO\Messenger\MessengerCountsDto;
use App\Admin\Application\DTO\Messenger\MessengerHistoryDto;
use App\Admin\Application\DTO\Messenger\MessengerMessageTypeRowDto;
use App\Admin\Application\DTO\Messenger\MessengerMonitoringReportDto;
use App\Admin\Application\DTO\Messenger\MessengerTransportRowDto;
use App\Admin\Infrastructure\Repository\MessengerMonitoringRepository;
use Psr\Clock\ClockInterface;

/**
 * Builds the admin Messenger page (ADR 019): counters of the selected period from messenger_metric, the live
 * queue state of every configured transport and recent failures.
 */
final readonly class MessengerMonitoringService
{
    /**
     * Configured transports (config/packages/messenger.yaml) => Doctrine queue name; null when the transport has
     * no queue to measure. Transports without an entry still appear when they handled messages.
     */
    public const array TRANSPORTS = [
        'async_priority_high' => 'high',
        'async_priority_low' => 'low',
        'async_mail' => 'mail',
        'scheduler_default' => null,
        'failed' => 'failed',
    ];

    public const string FAILED_QUEUE = 'failed';

    private const int FAILURE_ROWS = 20;

    private const string TIMEZONE = 'Europe/Berlin';

    public function __construct(
        private MessengerMonitoringRepository $repository,
        private ClockInterface $clock,
    ) {
    }

    public function report(MessengerMonitoringPeriod $period): MessengerMonitoringReportDto
    {
        $now = $this->clock->now();
        $since = $this->since($now, $period);
        $countsByTransport = $this->repository->countsByTransport($since);
        $queues = $this->repository->queues($now);

        $transports = [];
        $totals = new MessengerCountsDto();
        $pending = 0;
        $waiting = 0;
        foreach (array_unique([...array_keys(self::TRANSPORTS), ...array_keys($countsByTransport)]) as $name) {
            $queueName = self::TRANSPORTS[$name] ?? null;
            $queue = null === $queueName ? null : ($queues[$queueName] ?? ['pending' => 0, 'waiting' => 0, 'oldestPendingAt' => null]);
            $counts = $countsByTransport[$name] ?? new MessengerCountsDto();
            $transports[] = new MessengerTransportRowDto($name, $queueName, $queue['pending'] ?? null, $queue['waiting'] ?? null, $queue['oldestPendingAt'] ?? null, $counts);

            $totals = $totals->add($counts);
            if (null !== $queue && self::FAILED_QUEUE !== $queueName) {
                $pending += $queue['pending'];
                $waiting += $queue['waiting'];
            }
        }

        $messageTypes = array_map(
            static fn (array $row): MessengerMessageTypeRowDto => new MessengerMessageTypeRowDto($row['messageClass'], $row['transports'], $row['counts']),
            $this->repository->countsByMessageClass($since),
        );

        return new MessengerMonitoringReportDto(
            $period,
            $totals,
            $pending,
            $waiting,
            $queues[self::FAILED_QUEUE]['pending'] ?? 0,
            $this->repository->lastActivity(),
            $transports,
            $messageTypes,
            $this->repository->failures($since, self::FAILURE_ROWS),
            $this->history($now, $since, $period),
        );
    }

    /**
     * One point per hour, or per day (Europe/Berlin) for the 30-day period, from the start of the period to now.
     */
    private function history(\DateTimeImmutable $now, \DateTimeImmutable $since, MessengerMonitoringPeriod $period): MessengerHistoryDto
    {
        $daily = MessengerMonitoringPeriod::Month === $period;
        $counts = $this->repository->countsOverTime($since, $daily);
        $backlog = $this->repository->backlogOverTime($since, $daily);

        $utc = new \DateTimeZone('UTC');
        $berlin = new \DateTimeZone(self::TIMEZONE);
        $bucket = $daily ? $since->setTimezone($berlin)->setTime(0, 0) : $since;
        $step = $daily ? '+1 day' : '+1 hour';

        $buckets = $handled = $failed = $retried = $duration = $samples = [];
        while ($bucket <= $now) {
            $key = $bucket->setTimezone($utc)->format(\DATE_ATOM);
            $row = $counts[$key] ?? ['handled' => 0, 'failed' => 0, 'retried' => 0, 'attempts' => 0, 'durationMsSum' => 0];
            $buckets[] = $bucket;
            $handled[] = $row['handled'];
            $failed[] = $row['failed'];
            $retried[] = $row['retried'];
            $duration[] = 0 === $row['attempts'] ? null : (int) round($row['durationMsSum'] / $row['attempts']);
            $samples[] = $backlog[$key] ?? null;
            $bucket = $bucket->modify($step);
        }

        return new MessengerHistoryDto($daily ? 'day' : 'hour', $buckets, $handled, $failed, $retried, $duration, $samples);
    }

    /**
     * Start of the period, at a full hour so the first hourly row is counted completely.
     */
    private function since(\DateTimeImmutable $now, MessengerMonitoringPeriod $period): \DateTimeImmutable
    {
        $start = $now->setTimezone(new \DateTimeZone('UTC'))->modify(\sprintf('-%d hours', $period->hours()));

        return $start->setTime((int) $start->format('H'), 0);
    }
}
