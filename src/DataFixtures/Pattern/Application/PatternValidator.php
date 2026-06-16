<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Application;

use App\DataFixtures\Pattern\Dto\DistributionPattern;
use App\DataFixtures\Pattern\Infrastructure\PatternYamlSerializer;
use App\DataFixtures\Pattern\Infrastructure\Sampling\WeightedCategoricalSampler;

final readonly class PatternValidator
{
    private const float SUM_TOLERANCE = 0.02;

    /** @var list<string> */
    private const array FORBIDDEN_KEYS = [
        'notes',
        'caseIdHash',
        'case_id_hash',
        'hospital_name',
        'import_name',
    ];

    /** @var list<string> */
    private const array CATEGORICAL_KEYS = [
        'urgency',
        'department',
        'speciality',
        'assignment',
        'occasion',
        'gender',
        'age_bucket',
        'hour_of_day',
        'transport_type',
    ];

    public function __construct(
        private PatternYamlSerializer $serializer,
        private WeightedCategoricalSampler $categoricalSampler,
    ) {
    }

    /**
     * @return list<string>
     */
    public function validateAll(int $minSampleSize = 100): array
    {
        $errors = [];
        foreach ($this->serializer->listPatternNames() as $patternName) {
            $errors = [...$errors, ...$this->validatePattern($this->serializer->load($patternName), $minSampleSize)];
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    public function validatePattern(DistributionPattern $pattern, int $minSampleSize = 100): array
    {
        $errors = [];

        if (DistributionPattern::SCHEMA !== $pattern->schema) {
            $errors[] = sprintf('Pattern "%s" has unsupported schema "%s".', $pattern->name, $pattern->schema);
        }

        if ($pattern->version < 1) {
            $errors[] = sprintf('Pattern "%s" has invalid version.', $pattern->name);
        }

        $sampleSize = (int) ($pattern->meta['sample_size'] ?? 0);
        if ($sampleSize < $minSampleSize) {
            $errors[] = sprintf('Pattern "%s" sample_size %d is below minimum %d.', $pattern->name, $sampleSize, $minSampleSize);
        }

        foreach (self::FORBIDDEN_KEYS as $forbiddenKey) {
            if ($this->containsKey($pattern->distributions, $forbiddenKey)) {
                $errors[] = sprintf('Pattern "%s" contains forbidden key "%s".', $pattern->name, $forbiddenKey);
            }
        }

        foreach (self::CATEGORICAL_KEYS as $key) {
            $distribution = $pattern->categoricalDistribution($key);
            if ([] === $distribution) {
                continue;
            }

            $weights = $distribution;
            try {
                $this->categoricalSampler->normalize($weights);
            } catch (\InvalidArgumentException $exception) {
                $errors[] = sprintf('Pattern "%s" distribution "%s" is invalid: %s', $pattern->name, $key, $exception->getMessage());
                continue;
            }

            $sum = array_sum($weights);
            if (abs(1.0 - $sum) > self::SUM_TOLERANCE) {
                $errors[] = sprintf('Pattern "%s" distribution "%s" sums to %.4f (expected ~1.0).', $pattern->name, $key, $sum);
            }
        }

        foreach (['infection', 'indication_normalized', 'secondary_transport'] as $nullableKey) {
            $presence = $pattern->presenceProbability($nullableKey);
            if (null === $presence) {
                continue;
            }
            if ($presence < 0.0 || $presence > 1.0) {
                $errors[] = sprintf('Pattern "%s" presence "%s" must be between 0 and 1.', $pattern->name, $nullableKey);
            }
            $distribution = $pattern->categoricalDistribution($nullableKey);
            if ([] !== $distribution) {
                try {
                    $this->categoricalSampler->normalize($distribution);
                } catch (\InvalidArgumentException $exception) {
                    $errors[] = sprintf('Pattern "%s" distribution "%s" is invalid: %s', $pattern->name, $nullableKey, $exception->getMessage());
                }
            }
        }

        $percentiles = $pattern->transportTimePercentiles();
        if ([] !== $percentiles) {
            $previous = 0.0;
            foreach (['p25', 'p50', 'p75', 'p90'] as $key) {
                if (!isset($percentiles[$key])) {
                    continue;
                }
                if ($percentiles[$key] < $previous) {
                    $errors[] = sprintf('Pattern "%s" transport_time_minutes is not monotonic.', $pattern->name);
                    break;
                }
                $previous = $percentiles[$key];
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function containsKey(array $data, string $needle): bool
    {
        foreach ($data as $key => $value) {
            if ($key === $needle) {
                return true;
            }
            if (\is_array($value) && $this->containsKey($value, $needle)) {
                return true;
            }
        }

        return false;
    }
}
