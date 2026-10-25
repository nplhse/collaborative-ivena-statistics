<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Monitoring;

/**
 * Seconds and SQL statements per rebuild step. The rebuilder issues every statement itself,
 * so counting them where they are sent gives the query count of a run without a DBAL middleware.
 */
final class ClosureRebuildProfile
{
    /** @var array<string, array{seconds: float, queries: int}> */
    private array $steps = [];

    public function reset(): void
    {
        $this->steps = [];
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function time(string $step, callable $work, int $queries = 0): mixed
    {
        $started = microtime(true);
        try {
            return $work();
        } finally {
            $this->record($step, microtime(true) - $started, $queries);
        }
    }

    public function countQueries(string $step, int $queries = 1): void
    {
        $this->record($step, 0.0, $queries);
    }

    public function record(string $step, float $seconds, int $queries): void
    {
        $this->steps[$step] ??= ['seconds' => 0.0, 'queries' => 0];
        $this->steps[$step]['seconds'] += $seconds;
        $this->steps[$step]['queries'] += $queries;
    }

    public function queries(): int
    {
        return array_sum(array_column($this->steps, 'queries'));
    }

    /**
     * @return array<string, array{seconds: float, queries: int}>
     */
    public function steps(): array
    {
        $steps = $this->steps;
        uasort($steps, static fn (array $left, array $right): int => $right['seconds'] <=> $left['seconds']);

        return $steps;
    }
}
