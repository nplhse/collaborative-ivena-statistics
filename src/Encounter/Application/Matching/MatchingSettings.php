<?php

declare(strict_types=1);

namespace App\Encounter\Application\Matching;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Thresholds and windows of the matcher (`app.encounter.matching`, ADR 029).
 */
final readonly class MatchingSettings
{
    public PassWindow $pass1;

    public PassWindow $pass2;

    /**
     * @psalm-suppress PossiblyUnusedMethod Autowired.
     *
     * @param array{minutes_before: int, minutes_after: int, max_age_difference: int} $pass1
     * @param array{minutes_before: int, minutes_after: int, max_age_difference: int} $pass2
     */
    public function __construct(
        #[Autowire(param: 'app.encounter.matching.auto_accept_threshold')]
        public float $autoAcceptThreshold = 0.90,
        #[Autowire(param: 'app.encounter.matching.proposal_threshold')]
        public float $proposalThreshold = 0.50,
        #[Autowire(param: 'app.encounter.matching.pass1')]
        array $pass1 = ['minutes_before' => 20, 'minutes_after' => 45, 'max_age_difference' => 2],
        #[Autowire(param: 'app.encounter.matching.pass2')]
        array $pass2 = ['minutes_before' => 30, 'minutes_after' => 90, 'max_age_difference' => 5],
    ) {
        if ($proposalThreshold <= 0.0 || $proposalThreshold > $autoAcceptThreshold || $autoAcceptThreshold > 1.0) {
            throw new \InvalidArgumentException('Expected 0 < proposal threshold <= auto-accept threshold <= 1.');
        }

        $this->pass1 = new PassWindow($pass1['minutes_before'], $pass1['minutes_after'], $pass1['max_age_difference'], true);
        $this->pass2 = new PassWindow($pass2['minutes_before'], $pass2['minutes_after'], $pass2['max_age_difference'], false);
    }

    /**
     * @return array<int, PassWindow> pass number => window, in order
     */
    public function passes(): array
    {
        return [1 => $this->pass1, 2 => $this->pass2];
    }

    /** Largest minutes before the arrival over all passes. */
    public function widestMinutesBefore(): int
    {
        return max($this->pass1->minutesBefore, $this->pass2->minutesBefore);
    }

    /** Largest minutes after the arrival over all passes. */
    public function widestMinutesAfter(): int
    {
        return max($this->pass1->minutesAfter, $this->pass2->minutesAfter);
    }
}
