<?php

declare(strict_types=1);

namespace App\User\Application\Explore;

/** @psalm-suppress PossiblyUnusedProperty Consumed by Twig explore templates. */
final readonly class UserListItem
{
    /**
     * @param list<UserHospitalSummary> $hospitals
     */
    public function __construct(
        public string $publicId,
        public string $username,
        public bool $isAdmin,
        public bool $isParticipant,
        public bool $isBoardMember,
        public bool $isUserOnly,
        public array $hospitals,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
