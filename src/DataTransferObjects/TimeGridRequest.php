<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Enum\TimeGridMode;
use App\Model\Scope;
use App\Service\Statistics\Util\Period;
use Symfony\Component\HttpFoundation\Request;

final class TimeGridRequest
{
    public string $granularity = Period::YEAR;
    public string $periodKey = '2021-01-01';

    public string $metricsPreset = 'default';

    public TimeGridMode $mode = TimeGridMode::RAW;

    // primary scope
    public string $primaryType = 'public';
    public string $primaryId = 'all';

    // optional base scope (only used when mode = COMPARE)
    public ?string $baseType = null;
    public ?string $baseId = null;

    public static function fromRequest(Request $request): self
    {
        $self = new self();

        // Granularity
        $gran = strtolower((string) $request->query->get('gran', $self->granularity));
        $self->granularity = in_array($gran, Period::allGranularities(), true)
            ? $gran
            : Period::YEAR;

        // Period key
        $periodKey = (string) $request->query->get('key', $self->periodKey);
        $self->periodKey = Period::normalizePeriodKey($self->granularity, $periodKey);

        // Metrics preset
        $self->metricsPreset = (string) $request->query->get('metrics', 'default');

        // Mode
        $modeString = strtolower((string) $request->query->get('mode', TimeGridMode::RAW->value));
        $self->mode = TimeGridMode::tryFrom($modeString) ?? TimeGridMode::RAW;

        // Primary scope
        $self->primaryType = (string) $request->query->get('primaryType', $self->primaryType);
        $self->primaryId   = (string) $request->query->get('primaryId', $self->primaryId);

        // Base scope (optional)
        $self->baseType = $request->query->get('baseType');
        $self->baseId   = $request->query->get('baseId');

        return $self;
    }

    public function toPrimaryScope(): Scope
    {
        return new Scope(
            $this->primaryType,
            $this->primaryId,
            $this->granularity,
            $this->periodKey
        );
    }

    public function toBaseScopeOrNull(): ?Scope
    {
        if (TimeGridMode::COMPARE !== $this->mode) {
            return null;
        }

        if (!$this->baseType || !$this->baseId) {
            return null;
        }

        return new Scope(
            $this->baseType,
            $this->baseId,
            $this->granularity,
            $this->periodKey
        );
    }
}
