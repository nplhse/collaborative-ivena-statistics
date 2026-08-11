<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

final readonly class BackfillPageTranslationsResult
{
    /**
     * @param list<string> $errorMessages
     */
    public function __construct(
        public string $locale,
        public int $processed,
        public int $created,
        public int $skipped,
        public int $errors,
        public array $errorMessages = [],
    ) {
    }
}
