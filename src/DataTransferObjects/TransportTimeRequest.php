<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Model\Scope;
use App\Service\Statistics\TransportTimeBucketPresets;
use App\Service\Statistics\Util\Period;
use Symfony\Component\HttpFoundation\Request;

final class TransportTimeRequest
{
    public string $granularity = Period::YEAR;

    public string $periodKey = '2021-01-01';

    public string $scopeType = 'public';

    public string $scopeId = 'all';

    public string $view = 'int';

    public string $preset = 'total';

    public string $bucket = 'all';

    public bool $withProgress = false;

    public bool $withPhysician = true;

    public static function fromRequest(Request $request): self
    {
        $self = new self();

        // Granularity (default: year)
        $gran = strtolower($request->query->get('gran', $self->granularity));
        $self->granularity = \in_array($gran, Period::allGranularities(), true)
            ? $gran
            : Period::YEAR;

        // Period key (normalized based on granularity)
        $periodKey = $request->query->get('key', $self->periodKey);
        $self->periodKey = Period::normalizePeriodKey($self->granularity, $periodKey);

        // View (int|pct) – we reuse the same semantics as in TimeGrid
        $viewString = strtolower($request->query->get('view', 'int'));
        $self->view = 'pct' === $viewString ? 'pct' : 'int';

        // Primary scope (defaults: public/all)
        $self->scopeType = $request->query->get('scopeType', $self->scopeType);
        $self->scopeId = $request->query->get('scopeId', $self->scopeId);

        // Metrics preset
        $self->preset = $request->query->get('preset', 'total');

        // Bucket selection
        $bucket = $request->query->get('bucket', $self->bucket);

        $self->bucket = TransportTimeBucketPresets::isValid($bucket)
            ? $bucket
            : 'all';

        $self->withProgress = $request->query->getBoolean('progress', false);
        $self->withPhysician = $request->query->getBoolean('physician', true);

        return $self;
    }

    public function toScope(): Scope
    {
        return new Scope(
            $this->scopeType,
            $this->scopeId,
            $this->granularity,
            $this->periodKey
        );
    }
}
