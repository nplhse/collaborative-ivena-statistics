<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO\WidgetPayload;

final class WidgetPayloadNormalizer
{
    /**
     * @param array<string, mixed>|WidgetPayloadInterface $payload
     *
     * @return array<string, mixed>
     */
    public function normalize(array|WidgetPayloadInterface $payload): array
    {
        return \is_array($payload) ? $payload : $payload->toArray();
    }
}
