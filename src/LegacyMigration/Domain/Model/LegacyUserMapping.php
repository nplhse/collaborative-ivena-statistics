<?php

declare(strict_types=1);

namespace App\LegacyMigration\Domain\Model;

/** @psalm-suppress UnusedClass */
final readonly class LegacyUserMapping
{
    public function __construct(
        public int $legacyUserId,
        public int $newUserId,
        public ?string $legacyEmail,
    ) {
    }
}
