<?php

declare(strict_types=1);

namespace App\Shared\Application\MyRequests;

/**
 * One kind of request on the "My requests" page, with a link to start a new one and to the context's own pages.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the template.
 */
final readonly class MyRequestsSection
{
    /**
     * @param list<MyRequestEntry> $entries newest first
     */
    public function __construct(
        public string $key,
        public string $titleKey,
        public string $translationDomain,
        public string $icon,
        public int $weight,
        public array $entries,
        public ?string $newRoute = null,
        public ?string $newLabelKey = null,
        public ?string $overviewRoute = null,
        public ?string $overviewLabelKey = null,
    ) {
    }
}
