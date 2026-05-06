<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO\WidgetPayload;

final readonly class SimpleChartWidgetPayload implements WidgetPayloadInterface
{
    /**
     * @param list<string>         $labels
     * @param array<string, mixed> $extra
     */
    public function __construct(
        private string $chartType,
        private array $labels,
        private array $extra = [],
    ) {
    }

    #[\Override]
    public function toArray(): array
    {
        return array_merge([
            'chartType' => $this->chartType,
            'labels' => $this->labels,
        ], $this->extra);
    }
}
