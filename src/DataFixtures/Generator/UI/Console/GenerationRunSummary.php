<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\UI\Console;

use App\DataFixtures\Generator\Application\Run\GenerationRunHospitalStatus;
use App\DataFixtures\Generator\Application\Run\GenerationRunHospitalView;
use App\DataFixtures\Generator\Application\Run\GenerationRunStage;
use App\DataFixtures\Generator\Application\Run\GenerationRunView;
use App\DataFixtures\Generator\Application\Run\WorkerState;

/**
 * A short overview of a generation run for the wizard (#791): how far it is, what runs now, whether a worker takes
 * the background work. Counts and steps only, no ids; `app:generate:run:show` keeps the details.
 */
final class GenerationRunSummary
{
    private const int BAR_WIDTH = 20;

    private const int LABEL_WIDTH = 22;

    /** Hospitals in progress listed by name; more are counted. */
    private const int LISTED = 5;

    /**
     * @return list<string>
     */
    public static function lines(GenerationRunView $run, WorkerState $worker, \DateTimeImmutable $now): array
    {
        $done = $failed = 0;
        $inProgress = [];
        foreach ($run->hospitals as $hospital) {
            match ($hospital->status) {
                GenerationRunHospitalStatus::Completed => ++$done,
                GenerationRunHospitalStatus::Failed => ++$failed,
                default => $inProgress[] = $hospital,
            };
        }
        $total = \count($run->hospitals);

        $lines = [
            self::line('Run', \sprintf('%s, %s %s', self::status($run), $run->status->isTerminal() ? 'took' : 'running for', self::duration($run->createdAt, $run->finishedAt ?? $now))),
            self::line('Hospitals', \sprintf('%s %d/%d done%s%s', self::bar($done + $failed, $total), $done, $total, [] === $inProgress ? '' : \sprintf(', %d in progress', \count($inProgress)), 0 === $failed ? '' : \sprintf(', %d failed', $failed))),
        ];
        foreach (\array_slice($inProgress, 0, self::LISTED) as $hospital) {
            $lines[] = self::line($hospital->hospitalName, self::hospitalStep($hospital));
        }
        if (\count($inProgress) > self::LISTED) {
            $lines[] = self::line('', \sprintf('… and %d more', \count($inProgress) - self::LISTED));
        }
        if (GenerationRunStage::Finishing === $run->stage) {
            $lines[] = self::line('Final stage', $run->finishStep ?? 'starting');
        }
        if (!$run->status->isTerminal()) {
            $lines[] = self::line('Worker', self::worker($worker));
        }
        foreach ($run->hospitals as $hospital) {
            if (GenerationRunHospitalStatus::Failed === $hospital->status && null !== $hospital->error) {
                $lines[] = self::line('Problem', \sprintf('%s: %s', $hospital->hospitalName, GenerationRunFormatter::error($hospital->error)));
            }
        }
        if ($run->isStale($now)) {
            $lines[] = self::line('Note', \sprintf('no change for 30 minutes; retry with app:generate:run:retry %s', $run->id));
        }

        return $lines;
    }

    private static function status(GenerationRunView $run): string
    {
        return match (true) {
            $run->status->isTerminal() => $run->status->value,
            GenerationRunStage::Generating === $run->stage => 'generating the data',
            GenerationRunStage::Projecting === $run->stage => 'statistics in the background',
            GenerationRunStage::Finishing === $run->stage => 'final stage in the background',
            default => $run->stage->value,
        };
    }

    private static function hospitalStep(GenerationRunHospitalView $hospital): string
    {
        return match ($hospital->status) {
            GenerationRunHospitalStatus::Pending => 'waiting',
            GenerationRunHospitalStatus::Generating => 'generating the data',
            GenerationRunHospitalStatus::Generated => 'waiting for the worker',
            default => null === $hospital->projectionStep
                ? 'starting'
                : \sprintf('%s %s %d/%d', self::bar($hospital->projectionDone, $hospital->projectionTotal), $hospital->projectionStep, $hospital->projectionDone + 1, $hospital->projectionTotal),
        };
    }

    private static function worker(WorkerState $worker): string
    {
        return match (true) {
            !$worker->known => 'unknown (the queue is not a database table)',
            $worker->isBusy() => \sprintf('busy, %d job(s) in progress, %d waiting', $worker->inProgress, $worker->waiting),
            $worker->seemsMissing() => \sprintf('<comment>no worker? %d message(s) wait for %s: start make demo-consume</comment>', $worker->waiting, self::seconds($worker->oldestWaitingSeconds)),
            $worker->isIdle() => 'idle',
            default => \sprintf('%d message(s) waiting', $worker->waiting),
        };
    }

    private static function line(string $label, string $value): string
    {
        $label = mb_strimwidth($label, 0, self::LABEL_WIDTH - 1, '…');

        return \sprintf('<info>%s</info>%s%s', $label, str_repeat(' ', self::LABEL_WIDTH - mb_strwidth($label)), $value);
    }

    private static function bar(int $done, int $total): string
    {
        $filled = $total > 0 ? (int) round(min(1.0, (float) $done / (float) $total) * (float) self::BAR_WIDTH) : 0;

        return str_repeat('█', $filled).str_repeat('░', self::BAR_WIDTH - $filled);
    }

    private static function duration(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        return self::seconds(max(0, $to->getTimestamp() - $from->getTimestamp()));
    }

    private static function seconds(int $seconds): string
    {
        return $seconds >= 3600
            ? \sprintf('%d:%02d:%02d h', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
            : \sprintf('%d:%02d min', intdiv($seconds, 60), $seconds % 60);
    }
}
