<?php

declare(strict_types=1);

namespace App\Shared\Application\MyRequests;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * One request on the "My requests" page. The title is shown as entered; the status comes translated.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the template.
 */
final readonly class MyRequestEntry
{
    public function __construct(
        public string $title,
        public TranslatableMessage $status,
        public string $statusVariant,
        public \DateTimeImmutable $createdAt,
        public string $url,
        public ?string $subtitle = null,
    ) {
    }
}
