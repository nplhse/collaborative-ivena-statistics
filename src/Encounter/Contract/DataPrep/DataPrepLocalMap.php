<?php

declare(strict_types=1);

namespace App\Encounter\Contract\DataPrep;

/**
 * The private local map of a data preparation contribution (#1301): the template and example files offered for
 * download and the values the local map page lists (for `ed_visit` care levels and dispositions).
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the local map page and its downloads.
 */
final readonly class DataPrepLocalMap
{
    /**
     * @param array<string, mixed> $pageValues variables of the local map page template
     *
     * @psalm-suppress PossiblyUnusedMethod Contract for encounter schema plugins (ADR 037).
     */
    public function __construct(
        public string $templateFile,
        public string $exampleFile,
        public array $pageValues = [],
    ) {
    }
}
