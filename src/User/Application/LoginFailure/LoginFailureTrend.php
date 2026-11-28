<?php

declare(strict_types=1);

namespace App\User\Application\LoginFailure;

use App\User\Domain\Entity\LoginFailureDaily;

/**
 * Failed sign-ins of the last DAYS finished days for the admin dashboard (#1487). Attempts are failures plus refused
 * attempts. A day without a daily row is not counted (before counting started, or the job did not run) and stays null.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by Twig templates.
 */
final readonly class LoginFailureTrend
{
    public const int DAYS = 30;

    /**
     * @param list<array{date: \DateTimeImmutable, attempts: ?int, flagged: bool}> $days oldest first
     */
    private function __construct(
        public array $days,
        public int $attempts,
        public int $flaggedDays,
        public int $lockedAccounts,
    ) {
    }

    /**
     * @param list<LoginFailureDaily> $daily
     * @param \DateTimeImmutable      $today start of today
     */
    public static function build(array $daily, int $lockedAccounts, \DateTimeImmutable $today): self
    {
        $byDate = [];
        foreach ($daily as $row) {
            $byDate[$row->getDate()->format('Y-m-d')] = $row;
        }

        $days = [];
        $attempts = 0;
        $flaggedDays = 0;
        for ($offset = self::DAYS; $offset >= 1; --$offset) {
            $date = $today->sub(new \DateInterval(\sprintf('P%dD', $offset)));
            $row = $byDate[$date->format('Y-m-d')] ?? null;
            $dayAttempts = null !== $row ? $row->getFailures() + $row->getRefused() : null;
            $flagged = $row?->isFlagged() ?? false;
            $days[] = ['date' => $date, 'attempts' => $dayAttempts, 'flagged' => $flagged];
            $attempts += $dayAttempts ?? 0;
            $flaggedDays += $flagged ? 1 : 0;
        }

        return new self($days, $attempts, $flaggedDays, $lockedAccounts);
    }

    /**
     * Largest attempts of one day, at least 1, to scale the bars.
     *
     * @psalm-suppress PossiblyUnusedMethod Called from Twig.
     */
    public function peak(): int
    {
        return max(1, ...array_map(static fn (array $day): int => $day['attempts'] ?? 0, $this->days));
    }
}
