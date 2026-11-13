<?php

declare(strict_types=1);

namespace App\Encounter\Application\Matching;

/**
 * Points of one matcher version (ADR 029): the points per feature level and the weight of the "no match"
 * option. Each point is a base-2 logarithm: +1 doubles the weight of a candidate, −1 halves it.
 *
 * The production matcher uses {@see self::v1()}; a new matcher version is a new named set of points with
 * its own version (#1057). The match validation replays the planner with changed points
 * (`app:encounter:evaluate-matcher`) before such a version is introduced; replayed weights carry their own
 * version label and are never written.
 *
 * Levels (minutes are the matching anchor minus the IVENA arrival):
 *
 * | Key | Meaning | v1 |
 * |---|---|---|
 * | `time.core` | −5 … +15 min | +4 |
 * | `time.near` | −20 … −6 or +16 … +30 min | +2 |
 * | `time.late` | +31 … +45 min | 0 |
 * | `time.outside` | otherwise (pass 2 only) | −2 |
 * | `sex.equal` / `sex.unknown` / `sex.different` | equal / one side diverse or unknown / male vs female | +2 / 0 / −4 |
 * | `age.0` … `age.2`, `age.more` | age difference 0, 1, 2, more | +2, +1, 0, −2 |
 * | `arrival_route.ems` / `.unknown` / `.self` | arrival route of the visit | +1 / 0 / −2 |
 * | `physician.both` / `.mismatch` / `.none` | physician vehicle ↔ IVENA "with physician" | +2 / −1 / 0 |
 * | `work_accident.both` / `.one` / `.neither` | work accident on both, one or neither side | +2 / −1 / 0 |
 */
final readonly class MatchWeights
{
    public const string VERSION_1 = '1.0.0';

    public const int NO_MATCH_POINTS_1 = 4;

    /** @var array<string, int> */
    public const array POINTS_1 = [
        'time.core' => 4,
        'time.near' => 2,
        'time.late' => 0,
        'time.outside' => -2,
        'sex.equal' => 2,
        'sex.unknown' => 0,
        'sex.different' => -4,
        'age.0' => 2,
        'age.1' => 1,
        'age.2' => 0,
        'age.more' => -2,
        'arrival_route.ems' => 1,
        'arrival_route.unknown' => 0,
        'arrival_route.self' => -2,
        'physician.both' => 2,
        'physician.mismatch' => -1,
        'physician.none' => 0,
        'work_accident.both' => 2,
        'work_accident.one' => -1,
        'work_accident.neither' => 0,
    ];

    /**
     * @param array<string, int> $points every key of {@see self::POINTS_1}, nothing else
     */
    public function __construct(
        public string $version = self::VERSION_1,
        public int $noMatchPoints = self::NO_MATCH_POINTS_1,
        public array $points = self::POINTS_1,
    ) {
        if ('' === trim($version) || \strlen($version) > 16) {
            throw new \InvalidArgumentException('A matcher version has 1 to 16 characters.');
        }

        $missing = array_diff_key(self::POINTS_1, $points);
        $unknown = array_diff_key($points, self::POINTS_1);
        if ([] !== $missing || [] !== $unknown) {
            throw new \InvalidArgumentException(sprintf('Matcher points need exactly the keys %s.', implode(', ', array_keys(self::POINTS_1))));
        }
    }

    /**
     * Points of matcher v1, set by judgement (ADR 029).
     */
    public static function v1(): self
    {
        return new self();
    }

    /**
     * A copy with other points or another "no match" weight under a new version label, for replays.
     *
     * @param array<string, int> $points keys of {@see self::POINTS_1} to change
     */
    public function with(string $version, array $points = [], ?int $noMatchPoints = null): self
    {
        $unknown = array_diff_key($points, self::POINTS_1);
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(sprintf('Unknown matcher point "%s"; known: %s.', array_key_first($unknown), implode(', ', array_keys(self::POINTS_1))));
        }

        return new self($version, $noMatchPoints ?? $this->noMatchPoints, [...$this->points, ...$points]);
    }

    public function point(string $key): int
    {
        return $this->points[$key] ?? throw new \InvalidArgumentException(sprintf('Unknown matcher point "%s".', $key));
    }

    /**
     * Agreement of a pair that is the only candidate: 2^points / (2^points + 2^noMatchPoints).
     */
    public function agreementAlone(int $points): float
    {
        $weight = 2.0 ** (float) $points;

        return round($weight / ($weight + 2.0 ** (float) $this->noMatchPoints), MatchPlanner::SCORE_PRECISION);
    }
}
