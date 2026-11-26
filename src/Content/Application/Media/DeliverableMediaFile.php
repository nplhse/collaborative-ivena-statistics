<?php

declare(strict_types=1);

namespace App\Content\Application\Media;

/**
 * A media file the delivery route may send (ADR 039): where it is on disk and how it is described to the browser.
 */
final readonly class DeliverableMediaFile
{
    public function __construct(
        public string $absolutePath,
        public string $mimeType,
        public string $downloadName,
        public bool $immutable,
    ) {
    }
}
