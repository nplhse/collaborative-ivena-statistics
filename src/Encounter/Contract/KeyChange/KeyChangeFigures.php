<?php

declare(strict_types=1);

namespace App\Encounter\Contract\KeyChange;

/**
 * Figures of one schema for the key change page: effective IVENA links (automatic or confirmed) and manual
 * review decisions (confirmed or rejected) that a key change with "delete" removes.
 */
final readonly class KeyChangeFigures
{
    public function __construct(
        public int $links = 0,
        public int $decisions = 0,
    ) {
    }
}
