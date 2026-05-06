<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO\WidgetPayload;

final readonly class DistributionWidgetPayload implements WidgetPayloadInterface
{
    /**
     * @param list<array{labelTranslationKey: string, count: int, percent: float}> $rows
     * @param array<string, mixed>                                                 $extra
     */
    public function __construct(
        private string $titleTranslationKey,
        private array $rows,
        private array $extra = [],
    ) {
    }

    #[\Override]
    public function toArray(): array
    {
        return array_merge([
            'titleTranslationKey' => $this->titleTranslationKey,
            'rows' => $this->rows,
        ], $this->extra);
    }
}
