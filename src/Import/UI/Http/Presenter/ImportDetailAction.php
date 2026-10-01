<?php

declare(strict_types=1);

namespace App\Import\UI\Http\Presenter;

/** @psalm-suppress PossiblyUnusedProperty Consumed by the import detail template. */
final readonly class ImportDetailAction
{
    public function __construct(
        public string $label,
        public string $url,
        public string $icon,
        public string $variant,
    ) {
    }
}
