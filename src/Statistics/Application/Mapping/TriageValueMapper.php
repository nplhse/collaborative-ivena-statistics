<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

use App\Statistics\Application\Mapping\AllocationStatsUrgencyProjectionCode as UrgencyCode;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class TriageValueMapper implements ValueMapper
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function label(?int $value): string
    {
        $urgency = null === $value ? null : UrgencyCode::tryFrom($value);

        $key = match ($urgency) {
            UrgencyCode::Emergency => 'label.urgency.emergency',
            UrgencyCode::Inpatient => 'label.urgency.inpatient',
            UrgencyCode::Outpatient => 'label.urgency.outpatient',
            default => 'statistics.distribution.unknown_code',
        };

        return $this->translator->trans($key);
    }
}
