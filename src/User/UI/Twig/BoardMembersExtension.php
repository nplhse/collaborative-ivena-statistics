<?php

declare(strict_types=1);

namespace App\User\UI\Twig;

use App\User\Application\Explore\BoardMember;
use App\User\Infrastructure\Query\BoardMembersQuery;
use Twig\Attribute\AsTwigFunction;

/** Board members for the board members block of the CMS pages. */
final readonly class BoardMembersExtension
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private BoardMembersQuery $boardMembersQuery,
    ) {
    }

    /**
     * @return list<BoardMember>
     */
    #[AsTwigFunction('board_members')]
    public function boardMembers(): array
    {
        return ($this->boardMembersQuery)();
    }
}
