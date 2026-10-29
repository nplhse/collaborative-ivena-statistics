<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\UI\Console;

use App\DataFixtures\Generator\Application\Run\GenerationRunTracker;
use App\DataFixtures\Generator\Application\Run\GenerationRunView;
use App\DataFixtures\Generator\Infrastructure\Query\MessengerQueueQuery;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Follows a generation run in the terminal until it is done (#791): the summary is redrawn in place every few
 * seconds; without a terminal it is printed whenever something other than the elapsed time changes. Stopping the
 * command (Ctrl+C) only ends the view: the worker goes on.
 */
final readonly class GenerationRunWatch
{
    public const int INTERVAL_SECONDS = 2;

    public function __construct(
        private GenerationRunTracker $runs,
        private MessengerQueueQuery $queue,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return GenerationRunView|null the run as last seen; null when it is unknown
     */
    public function follow(OutputInterface $output, string $runId): ?GenerationRunView
    {
        $section = $output instanceof ConsoleOutputInterface && $output->isDecorated() ? $output->section() : null;
        $printed = null;
        while (true) {
            $run = $this->runs->get($runId);
            if (!$run instanceof GenerationRunView) {
                return null;
            }
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $lines = GenerationRunSummary::lines($run, $this->queue->workerState(), $now);
            if ($section instanceof ConsoleSectionOutput) {
                $section->overwrite($lines);
            } elseif (\array_slice($lines, 1) !== $printed) {
                $output->writeln([...$lines, '']);
                $printed = \array_slice($lines, 1);
            }

            if ($run->status->isTerminal() || $run->isStale($now)) {
                return $run;
            }
            $this->clock->sleep(self::INTERVAL_SECONDS);
        }
    }
}
