<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO\WidgetPayload;

interface WidgetPayloadInterface
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
