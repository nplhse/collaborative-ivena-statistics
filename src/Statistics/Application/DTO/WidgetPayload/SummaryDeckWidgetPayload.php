<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO\WidgetPayload;

final readonly class SummaryDeckWidgetPayload implements WidgetPayloadInterface
{
    /**
     * @param array<string, mixed> $kpi
     * @param array<string, mixed> $gender
     * @param array<string, mixed> $urgency
     * @param array<string, mixed> $extra
     */
    public function __construct(
        private array $kpi,
        private array $gender,
        private array $urgency,
        private array $extra = [],
    ) {
    }

    #[\Override]
    public function toArray(): array
    {
        return array_merge([
            'kpi' => $this->kpi,
            'gender' => $this->gender,
            'urgency' => $this->urgency,
        ], $this->extra);
    }
}
