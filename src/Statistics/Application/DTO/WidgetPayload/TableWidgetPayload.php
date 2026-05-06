<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO\WidgetPayload;

final readonly class TableWidgetPayload implements WidgetPayloadInterface
{
    /**
     * @param list<string>                                   $headerTranslationKeys
     * @param list<list<string|int|float|null>>             $rows
     * @param array<string, mixed>                          $extra
     */
    public function __construct(
        private array $headerTranslationKeys,
        private array $rows,
        private array $extra = [],
    ) {
    }

    public function toArray(): array
    {
        return array_merge([
            'headerTranslationKeys' => $this->headerTranslationKeys,
            'rows' => $this->rows,
        ], $this->extra);
    }
}
