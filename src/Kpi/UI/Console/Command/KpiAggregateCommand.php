<?php

declare(strict_types=1);

namespace App\Kpi\UI\Console\Command;

use App\Kpi\Application\Service\KpiAggregationService;
use App\Kpi\UI\Console\Input\KpiAggregateInput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:kpi:aggregate',
    description: 'Aggregate daily KPI metrics from import data into kpi_daily (idempotent per day).',
)]
final readonly class KpiAggregateCommand
{
    private const string TIMEZONE = 'Europe/Berlin';

    public function __construct(
        private KpiAggregationService $aggregationService,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[MapInput] KpiAggregateInput $input,
    ): int {
        $tz = new \DateTimeZone(self::TIMEZONE);

        try {
            $dates = $this->resolveDates($input, $tz);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $totalRows = 0;
        $daysWithData = 0;

        foreach ($dates as $aggregationDate) {
            try {
                $count = $this->aggregationService->aggregateForDate($aggregationDate);
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
    private function resolveDates(KpiAggregateInput $input, \DateTimeZone $tz): array
    {
        if (\is_string($input->date) && '' !== $input->date) {
            return [$this->parseDate($input->date, $tz)];
        }

        $days = $input->days;
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
