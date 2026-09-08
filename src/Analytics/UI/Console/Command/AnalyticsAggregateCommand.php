<?php

declare(strict_types=1);

namespace App\Analytics\UI\Console\Command;

use App\Analytics\Application\Service\AnalyticsDailyAggregationService;
use App\Analytics\Application\Service\AnalyticsRawRetentionService;
use App\Analytics\Domain\AnalyticsCalendar;
use App\Analytics\UI\Console\Input\AnalyticsAggregateInput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:analytics:aggregate',
    description: 'Aggregate completed analytics days into daily statistics and purge expired raw events (idempotent per day).',
)]
final readonly class AnalyticsAggregateCommand
{
    public function __construct(
        private AnalyticsDailyAggregationService $aggregationService,
        private AnalyticsRawRetentionService $retentionService,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[MapInput] AnalyticsAggregateInput $input,
    ): int {
        if ($input->cleanupOnly && null !== $input->date && '' !== $input->date) {
            $io->error('The --cleanup-only option cannot be combined with --date.');

            return Command::FAILURE;
        }

        if ($input->cleanupOnly && $input->noCleanup) {
            $io->error('The --cleanup-only and --no-cleanup options cannot be combined.');

            return Command::FAILURE;
        }

        if ($input->dryRun && !$input->cleanupOnly && !$input->noCleanup) {
            $io->warning('--dry-run only previews cleanup; aggregation still writes daily statistics unless --cleanup-only is set.');
        }

        try {
            if (!$input->cleanupOnly) {
                $dates = $this->resolveDates($input);
                $this->aggregateDates($io, $dates);
            }

            if (!$input->noCleanup) {
                $this->runCleanup($io, $input->dryRun);
            }
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<\DateTimeImmutable> $dates
     */
    private function aggregateDates(SymfonyStyle $io, array $dates): void
    {
        $totalRows = 0;
        $totalRequests = 0;
        $totalEvents = 0;
        $daysWithData = 0;

        foreach ($dates as $aggregationDate) {
            $result = $this->aggregationService->aggregateForDate($aggregationDate);
            $totalRows += $result->aggregateRowsWritten;
            $totalRequests += $result->rawRequestCount;
            $totalEvents += $result->rawEventCount;
            if ($result->rawRequestCount > 0 || $result->rawEventCount > 0) {
                ++$daysWithData;
            }
        }

        $dayCount = \count($dates);
        $summary = sprintf(
            'Analytics aggregation finished: %d aggregate row(s) written from %d request(s) and %d event(s); %d of %d day(s) with data%s.',
            $totalRows,
            $totalRequests,
            $totalEvents,
            $daysWithData,
            $dayCount,
            $this->formatPeriodSuffix($dates),
        );

        if (0 === $totalRequests && 0 === $totalEvents) {
            $summary .= ' No raw analytics rows in the selected period.';
        }

        $io->success($summary);
    }

    private function runCleanup(SymfonyStyle $io, bool $dryRun): void
    {
        $result = $this->retentionService->purgeExpiredRaw($dryRun);
        $verb = $dryRun ? 'would delete' : 'deleted';
        $dateCount = \count($result->datesCleaned);
        $message = sprintf(
            'Raw retention %s %d request(s) and %d event(s) across %d aggregated day(s) older than %d day(s).',
            $verb,
            $result->requestsDeleted,
            $result->eventsDeleted,
            $dateCount,
            $this->retentionService->retentionDays(),
        );

        if ($dryRun) {
            $io->note($message);

            return;
        }

        $io->success($message);
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    private function resolveDates(AnalyticsAggregateInput $input): array
    {
        if (\is_string($input->date) && '' !== $input->date) {
            return [$this->parseDate($input->date)];
        }

        $days = $input->days;
        if ($days < 1 || $days > 366) {
            throw new \InvalidArgumentException('The --days option must be between 1 and 366.');
        }

        $yesterday = AnalyticsCalendar::yesterday();
        $dates = [];
        for ($offset = 0; $offset < $days; ++$offset) {
            $day = $yesterday->modify(sprintf('-%d days', $offset));
            if (false === $day) {
                throw new \InvalidArgumentException('Failed to compute aggregation date.');
            }
            $dates[] = $day;
        }

        return array_reverse($dates);
    }

    /**
     * @param list<\DateTimeImmutable> $dates
     */
    private function formatPeriodSuffix(array $dates): string
    {
        if ([] === $dates) {
            return '';
        }

        $first = $dates[0]->format('Y-m-d');
        $last = $dates[\count($dates) - 1]->format('Y-m-d');

        return $first === $last ? sprintf(' (%s)', $first) : sprintf(' (%s … %s)', $first, $last);
    }

    private function parseDate(string $dateOption): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $dateOption, AnalyticsCalendar::timezone());
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            false === $parsed
            || ($errors['warning_count'] ?? 0) > 0
            || ($errors['error_count'] ?? 0) > 0
            || $parsed->format('Y-m-d') !== $dateOption
        ) {
            throw new \InvalidArgumentException(sprintf('Invalid --date "%s". Expected format YYYY-MM-DD.', $dateOption));
        }

        return AnalyticsCalendar::startOfDay($parsed);
    }
}
