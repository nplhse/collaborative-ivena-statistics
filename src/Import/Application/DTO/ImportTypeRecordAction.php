<?php

declare(strict_types=1);

namespace App\Import\Application\DTO;

/**
 * A link to the records of an import that an import type extension adds to the actions of the detail page.
 *
 * @psalm-suppress PossiblyUnusedProperty Consumed by ImportDetailViewFactory.
 */
final readonly class ImportTypeRecordAction
{
    /**
     * @param string $label translated button label
     * @param string $icon  Tabler icon name (`tabler:…`)
     */
    public function __construct(
        public string $label,
        public string $url,
        public string $icon,
    ) {
    }
}
