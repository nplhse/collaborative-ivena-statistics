<?php

declare(strict_types=1);

namespace App\User\Application\Explore;

/** @psalm-suppress PossiblyUnusedProperty Consumed by Twig explore templates. */
final readonly class UserHospitalSummary
{
    public function __construct(
        public string $publicId,
        public string $name,
        public string $relation,
    ) {
    }
}
