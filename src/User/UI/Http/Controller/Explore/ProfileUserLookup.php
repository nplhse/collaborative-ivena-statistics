<?php

declare(strict_types=1);

namespace App\User\UI\Http\Controller\Explore;

use App\User\Domain\Entity\User;
use App\User\Infrastructure\Repository\UserRepository;

/**
 * Finds the user of a profile URL `/explore/user/{username}` (#1353). The lookup ignores the case; the controllers
 * redirect to the canonical spelling.
 */
final readonly class ProfileUserLookup
{
    /**
     * Route requirement of `{username}`: one path segment. Wider than UserUsernameConstraints::PATTERN, because
     * usernames from before that rule (e.g. with spaces, see NormalizeUsernamesCommand) still get a profile link.
     */
    public const string ROUTE_REQUIREMENT = '[^/]+';

    public function __construct(
        private UserRepository $users,
    ) {
    }

    public function find(string $username): ?User
    {
        return $this->users->findOneByUsernameInsensitive($username);
    }
}
