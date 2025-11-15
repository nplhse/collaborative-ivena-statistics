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

    public string $view = 'counts';

    public static function fromRequest(Request $request): self
    {
        $self = new self();

        // Granularity
        $gran = strtolower($request->query->get('gran', $self->granularity));
        $self->granularity = in_array($gran, Period::allGranularities(), true)
            ? $gran
            : Period::YEAR;

        // Period key
        $periodKey = $request->query->get('key', $self->periodKey);
        $self->periodKey = Period::normalizePeriodKey($self->granularity, $periodKey);

        // Metrics preset
        $self->metricsPreset = $request->query->get('metrics', 'default');

        // Mode
        $modeString = strtolower($request->query->get('mode', TimeGridMode::RAW->value));
        $self->mode = TimeGridMode::tryFrom($modeString) ?? TimeGridMode::RAW;

        // View
        $viewString = strtolower($request->query->get('view', 'int'));
        $self->view = 'pct' === $viewString ? 'pct' : 'int';

        // Primary scope
        $self->primaryType = $request->query->get('primaryType', $self->primaryType);
        $self->primaryId = $request->query->get('primaryId', $self->primaryId);

        // Base scope (optional)
        $baseType = $request->query->get('baseType');
        $baseId = $request->query->get('baseId');

        $self->baseType = '' !== $baseType ? $baseType : null;
        $self->baseId = '' !== $baseId ? $baseId : null;

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

        if (null === $this->baseType || null === $this->baseId) {
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
