<?php

declare(strict_types=1);

namespace App\Content\Application\Reaction;

/** Who is told about reactions to a target, how it is named and where it opens (#973). Holds no content text. */
final readonly class ReactionNotificationTarget
{
    /**
     * @param array<string, scalar> $routeParameters
     */
    public function __construct(
        public ?int $ownerId,
        public ?string $title,
        public string $route,
        public array $routeParameters = [],
        public ?string $fragment = null,
    ) {
    }
}
