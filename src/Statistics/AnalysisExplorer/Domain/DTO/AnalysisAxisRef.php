<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\DTO;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Exception\InvalidExplorerConfigException;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisDimension;

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
            $grain = $this->resolvedGrain();
            if (AnalysisDimensionGrain::Total === $grain) {
                return AnalysisDimension::ALLOCATION_TOTAL_KEY;
            }

            return $grain->registryTemporalKey();
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
        $dimensionValue = $state['dimension'] ?? null;
        if (!\is_string($dimensionValue) || '' === $dimensionValue) {
            throw new InvalidExplorerConfigException('stats.analysis_explorer.validation.unsupported_dimension');
        }

        $dimensionKey = AnalysisDimensionKey::tryFrom($dimensionValue);
        if (!$dimensionKey instanceof AnalysisDimensionKey) {
            throw new InvalidExplorerConfigException('stats.analysis_explorer.validation.unsupported_dimension', ['rows' => $dimensionValue]);
        }

        $grainValue = $state['grain'] ?? null;
        $grain = null;
        if (\is_string($grainValue) && '' !== $grainValue) {
            $grain = AnalysisDimensionGrain::tryFrom($grainValue);
            if (!$grain instanceof AnalysisDimensionGrain) {
                throw new InvalidExplorerConfigException('stats.analysis_explorer.validation.unsupported_dimension', ['grain' => $grainValue]);
            }
        }

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
