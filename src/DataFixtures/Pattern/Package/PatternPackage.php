<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Package;

use App\DataFixtures\Pattern\Package\Validation\ValidPatternPackage;

/**
 * An allocation pattern package, format `ivena-distribution-patterns` (ADR 022).
 *
 * Segment patterns keyed by segment name, indication groups keyed by PZC code prefix, and the group
 * for codes without a group of their own. Package rules run as a class constraint.
 */
#[ValidPatternPackage]
final readonly class PatternPackage
{
    /**
     * @param array<string, SegmentPattern>            $segments
     * @param array<array-key, IndicationGroupPattern> $indicationGroups PZC code prefix → group
     */
    public function __construct(
        public PatternPackageMeta $meta,
        public array $segments,
        public array $indicationGroups,
        public string $fallbackGroup,
    ) {
    }

    /**
     * @param array<string, mixed> $data a package that passed the structure check
     */
    public static function fromArray(array $data): self
    {
        /* @var array{meta: array{format: string, version: int, created_at: string, source: string, counts: array<string, int>, checksum: array{algorithm: string, value: string}, app_version?: string, catalog?: string, notes?: string}, segments: array<string, array<string, mixed>>, indication_groups: array<array-key, array<string, mixed>>, fallback_group: string} $data */
        return new self(
            PatternPackageMeta::fromArray($data['meta']),
            array_map(SegmentPattern::fromArray(...), $data['segments']),
            array_map(IndicationGroupPattern::fromArray(...), $data['indication_groups']),
            $data['fallback_group'],
        );
    }

    public function withMeta(PatternPackageMeta $meta): self
    {
        return new self($meta, $this->segments, $this->indicationGroups, $this->fallbackGroup);
    }

    /**
     * Everything except `meta`, in file order; the checksum is computed over it.
     *
     * @return array{segments: array<string, array<string, mixed>>, indication_groups: array<array-key, array<string, mixed>>, fallback_group: string}
     */
    public function content(): array
    {
        return [
            'segments' => array_map(static fn (SegmentPattern $segment): array => $segment->toArray(), $this->segments),
            'indication_groups' => array_map(static fn (IndicationGroupPattern $group): array => $group->toArray(), $this->indicationGroups),
            'fallback_group' => $this->fallbackGroup,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['meta' => $this->meta->toArray()] + $this->content();
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $codes = [];
        foreach ($this->segments as $segment) {
            $codes += $segment->indications->shares;
        }

        return [
            'segments' => \count($this->segments),
            'indication_groups' => \count($this->indicationGroups),
            'indications' => \count($codes),
        ];
    }
}
