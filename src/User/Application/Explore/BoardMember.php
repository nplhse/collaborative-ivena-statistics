<?php

declare(strict_types=1);

namespace App\User\Application\Explore;

/**
 * A board member for the board members block of the CMS pages.
 *
 * @psalm-suppress PossiblyUnusedProperty Consumed by Twig templates.
 */
final readonly class BoardMember
{
    /**
     * @param list<UserHospitalSummary> $hospitals
     */
    public function __construct(
        public int $userId,
        public string $username,
        public ?string $displayName,
        public array $hospitals,
    ) {
    }
}
