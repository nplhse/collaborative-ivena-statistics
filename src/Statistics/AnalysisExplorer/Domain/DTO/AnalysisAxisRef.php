<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\DTO;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;

final readonly class AnalysisAxisRef
{
    public function __construct(
        public AnalysisDimensionKey $dimensionKey,
        public ?AnalysisDimensionGrain $grain = null,
    ) {
    }

    public function resolvedGrain(): AnalysisDimensionGrain
    {
        if ($this->dimensionKey->isTemporalPrimary()) {
            return $this->grain ?? AnalysisDimensionGrain::Month;
        }

        return $this->grain ?? AnalysisDimensionGrain::Total;
    }

    public function toRegistryKey(): string
    {
        if ($this->dimensionKey->isTemporalPrimary()) {
            return $this->resolvedGrain()->registryTemporalKey();
        }

        return $this->dimensionKey->registryKey();
    }

    public function isTemporal(): bool
    {
        return $this->dimensionKey->isTemporalPrimary()
            || $this->resolvedGrain()->isTemporal();
    }

    public function isBreakdown(): bool
    {
        return !$this->dimensionKey->isTemporalPrimary();
    }

    /**
     * @return array{dimension: string, grain: ?string}
     */
    public function toStateArray(): array
    {
        $grain = $this->resolvedGrain();

        return [
            'dimension' => $this->dimensionKey->value,
            'grain' => $this->dimensionKey->isTemporalPrimary() || $grain->isTemporal()
                ? $grain->value
                : (AnalysisDimensionGrain::Total === $grain ? 'total' : $grain->value),
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function fromStateArray(array $state): self
    {
        $dimensionKey = AnalysisDimensionKey::tryFrom((string) ($state['dimension'] ?? 'time'))
            ?? AnalysisDimensionKey::Time;
        $grainValue = $state['grain'] ?? null;
        $grain = \is_string($grainValue)
            ? AnalysisDimensionGrain::tryFrom($grainValue)
            : null;

        return new self(
            dimensionKey: $dimensionKey,
            grain: $grain,
        );
    }

    public static function time(AnalysisDimensionGrain $grain): self
    {
        return new self(AnalysisDimensionKey::Time, $grain);
    }

    public static function breakdown(AnalysisDimensionKey $dimensionKey): self
    {
        return new self($dimensionKey, AnalysisDimensionGrain::Total);
    }
}
