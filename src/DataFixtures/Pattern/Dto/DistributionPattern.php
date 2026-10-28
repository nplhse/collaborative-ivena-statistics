<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Dto;

use App\DataFixtures\Pattern\Application\IndicationGroupTable;

final readonly class DistributionPattern
{
    /**
     * @param array<string, mixed>      $distributions
     * @param IndicationGroupTable|null $indicationGroups what follows from the indication; null for the code defaults
     */
    public function __construct(
        public array $distributions,
        private ?IndicationGroupTable $indicationGroups = null,
    ) {
    }

    public function indicationGroups(): IndicationGroupTable
    {
        return $this->indicationGroups ?? IndicationGroupTable::defaults();
    }

    /**
     * @return array<string, float>
     */
    public function categoricalDistribution(string $key): array
    {
        $distribution = $this->distributions[$key] ?? null;
        if (!\is_array($distribution)) {
            return [];
        }

        /** @var array<string, float> $normalized */
        $normalized = [];
        foreach ($distribution as $label => $weight) {
            if ('_present' === $label || !\is_numeric($weight)) {
                continue;
            }
            $normalized[(string) $label] = (float) $weight;
        }

        return $normalized;
    }

    public function presenceProbability(string $key): ?float
    {
        $distribution = $this->distributions[$key] ?? null;
        if (!\is_array($distribution)) {
            return null;
        }

        if (!isset($distribution['_present']) || !\is_numeric($distribution['_present'])) {
            return null;
        }

        return (float) $distribution['_present'];
    }

    /**
     * A copy with one distribution replaced, for example the indication mix of a scenario.
     *
     * @param array<int|string, float> $distribution labels, e.g. PZC codes (PHP keys most of them as int)
     */
    public function withDistribution(string $key, array $distribution): self
    {
        $distributions = $this->distributions;
        $distributions[$key] = $distribution;

        return new self($distributions, $this->indicationGroups);
    }

    /**
     * A copy with the given flag probabilities replaced.
     *
     * @param array<string, float> $flags
     */
    public function withFlags(array $flags): self
    {
        $distributions = $this->distributions;
        $distributions['flags'] = $flags + $this->flagProbabilities();

        return new self($distributions, $this->indicationGroups);
    }

    /**
     * A copy for a hospital that does not offer the given departments.
     *
     * @param list<string> $departments
     */
    public function withoutDepartments(array $departments): self
    {
        $distributions = $this->distributions;
        $distributions['departments_not_offered'] = $departments;

        return new self($distributions, $this->indicationGroups);
    }

    /**
     * @return list<string>
     */
    public function departmentsNotOffered(): array
    {
        $departments = $this->distributions['departments_not_offered'] ?? null;
        if (!\is_array($departments)) {
            return [];
        }

        return array_values(array_map(strval(...), array_filter($departments, \is_string(...))));
    }

    /**
     * A copy for a hospital that lacks the capability for indications with the given PZC code prefixes.
     *
     * @param list<string> $prefixes
     */
    public function withoutIndications(array $prefixes): self
    {
        $distributions = $this->distributions;
        $distributions['indications_blocked'] = $prefixes;

        return new self($distributions, $this->indicationGroups);
    }

    /**
     * Whether the hospital lacks the capability for the code (a blocked prefix matches it).
     */
    public function blocksIndication(string $code): bool
    {
        $prefixes = $this->distributions['indications_blocked'] ?? null;
        if (!\is_array($prefixes)) {
            return false;
        }

        return array_any($prefixes, fn ($prefix): bool => \is_string($prefix) && '' !== $prefix && str_starts_with($code, $prefix));
    }

    /**
     * A copy with the case mix tilt of a hospital: factors on indication categories (first PZC
     * digit), on age bands from 65 on and on urgency 1 (see MixTilt).
     *
     * @param array{categories: array<int, float>, old_age: float, urgent: float} $tilt
     */
    public function withTilt(array $tilt): self
    {
        $distributions = $this->distributions;
        $distributions['tilt'] = $tilt;

        return new self($distributions, $this->indicationGroups);
    }

    /**
     * Indication weights with the category factors of the hospital applied.
     *
     * @param array<int|string, float> $weights PZC code → weight
     *
     * @return array<int|string, float>
     */
    public function tiltIndications(array $weights): array
    {
        $categories = $this->tilt()['categories'];
        if ([] === $categories) {
            return $weights;
        }

        foreach ($weights as $code => $weight) {
            $weights[$code] = $weight * ($categories[(int) substr(str_pad((string) $code, 3, '0', \STR_PAD_LEFT), 0, 1)] ?? 1.0);
        }

        return $weights;
    }

    /**
     * Urgency weights with the urgency 1 factor of the hospital applied.
     *
     * @param array<int|string, float> $weights urgency → weight
     *
     * @return array<int|string, float>
     */
    public function tiltUrgency(array $weights): array
    {
        $urgent = $this->tilt()['urgent'];
        if (1.0 !== $urgent && isset($weights[1])) {
            $weights[1] *= $urgent;
        }

        return $weights;
    }

    /**
     * Age band weights with the old age factor of the hospital applied to bands from 65 on.
     *
     * @param array<string, float> $weights age range ("65-99") → weight
     *
     * @return array<string, float>
     */
    public function tiltAges(array $weights): array
    {
        $old = $this->tilt()['old_age'];
        if (1.0 === $old) {
            return $weights;
        }

        foreach ($weights as $range => $weight) {
            if ((int) explode('-', $range)[0] >= 65) {
                $weights[$range] = $weight * $old;
            }
        }

        return $weights;
    }

    /**
     * @return array{categories: array<int, float>, old_age: float, urgent: float}
     */
    private function tilt(): array
    {
        $tilt = $this->distributions['tilt'] ?? null;
        if (!\is_array($tilt)) {
            return ['categories' => [], 'old_age' => 1.0, 'urgent' => 1.0];
        }

        $categories = [];
        foreach (\is_array($tilt['categories'] ?? null) ? $tilt['categories'] : [] as $digit => $factor) {
            if (is_numeric($factor)) {
                $categories[(int) $digit] = (float) $factor;
            }
        }

        return [
            'categories' => $categories,
            'old_age' => is_numeric($tilt['old_age'] ?? null) ? (float) $tilt['old_age'] : 1.0,
            'urgent' => is_numeric($tilt['urgent'] ?? null) ? (float) $tilt['urgent'] : 1.0,
        ];
    }

    /**
     * @return array<string, float>
     */
    public function flagProbabilities(): array
    {
        $flags = $this->distributions['flags'] ?? null;
        if (!\is_array($flags)) {
            return [];
        }

        /** @var array<string, float> $normalized */
        $normalized = [];
        foreach ($flags as $name => $probability) {
            if (\is_numeric($probability)) {
                $normalized[(string) $name] = (float) $probability;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, float>
     */
    public function transportTimePercentiles(): array
    {
        $percentiles = $this->distributions['transport_time_minutes'] ?? null;
        if (!\is_array($percentiles)) {
            return [];
        }

        /** @var array<string, float> $normalized */
        $normalized = [];
        foreach (['p25', 'p50', 'p75', 'p90'] as $key) {
            if (isset($percentiles[$key]) && \is_numeric($percentiles[$key])) {
                $normalized[$key] = (float) $percentiles[$key];
            }
        }

        return $normalized;
    }
}
