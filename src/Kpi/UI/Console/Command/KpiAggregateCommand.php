<?php

declare(strict_types=1);

namespace App\Kpi\UI\Console\Command;

use App\Kpi\Application\Service\KpiAggregationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:kpi:aggregate',
    description: 'Aggregate daily KPI metrics from import data into kpi_daily (idempotent per day).',
)]
final class KpiAggregateCommand extends Command
{
    private const string TIMEZONE = 'Europe/Berlin';

    /** Matches the 30-day window shown on the admin KPI dashboard. */
    private const int DEFAULT_DAYS_WITHOUT_DATE = 30;

    public function __construct(
        private readonly KpiAggregationService $aggregationService,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption(
                'date',
                null,
                InputOption::VALUE_REQUIRED,
                'Aggregate a single calendar day (YYYY-MM-DD, Europe/Berlin).',
            )
            ->addOption(
                'days',
                null,
                InputOption::VALUE_REQUIRED,
                'When --date is omitted: number of days to aggregate ending yesterday (default: 30, matches dashboard). Use 1 for cron.',
                (string) self::DEFAULT_DAYS_WITHOUT_DATE,
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tz = new \DateTimeZone(self::TIMEZONE);

        try {
            $dates = $this->resolveDates($input, $tz);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $totalRows = 0;
        $daysWithData = 0;

        foreach ($dates as $date) {
            try {
                $count = $this->aggregationService->aggregateForDate($date);
            } catch (\Throwable $exception) {
                $io->error($exception->getMessage());

                return Command::FAILURE;
            }

            $totalRows += $count;
            if ($count > 0) {
                ++$daysWithData;
            }
        }

        $dayCount = \count($dates);
        $summary = sprintf(
            'KPI aggregation finished: %d row(s) written, %d of %d day(s) with data%s.',
            $totalRows,
            $daysWithData,
            $dayCount,
            $this->formatPeriodSuffix($dates),
        );

        if (0 === $totalRows) {
            $summary .= ' No rows written — only final imports count; Pending/Running are excluded.';
        }

        $io->success($summary);

        return Command::SUCCESS;
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    private function resolveDates(InputInterface $input, \DateTimeZone $tz): array
    {
        $dateOption = $input->getOption('date');

        if (\is_string($dateOption) && '' !== $dateOption) {
            return [$this->parseDate($dateOption, $tz)];
        }

        $days = (int) $input->getOption('days');
        if ($days < 1 || $days > 366) {
            throw new \InvalidArgumentException('The --days option must be between 1 and 366.');
        }

        $yesterday = new \DateTimeImmutable('yesterday', $tz);
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

    private function parseDate(string $dateOption, \DateTimeZone $tz): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $dateOption, $tz);
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            false === $parsed
            || ($errors['warning_count'] ?? 0) > 0
            || ($errors['error_count'] ?? 0) > 0
            || $parsed->format('Y-m-d') !== $dateOption
        ) {
            throw new \InvalidArgumentException(sprintf('Invalid --date "%s". Expected format YYYY-MM-DD.', $dateOption));
        }

        return $parsed;
    }
}
