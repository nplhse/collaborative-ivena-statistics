<?php

declare(strict_types=1);

namespace App\Allocation\Application\DataSources;

/**
 * One geographic data source on the public page. Without a name it is the tile server the operator configured.
 *
 * @psalm-immutable
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the data sources template.
 */
final readonly class DataSource
{
    /**
     * @param list<string> $uses         translation keys of what the source is used for
     * @param list<string> $attributions required source notes
     */
    public function __construct(
        public ?string $name,
        public ?string $url,
        public array $uses,
        public ?string $licence,
        public ?string $licenceUrl,
        public array $attributions,
    ) {
    }
}
