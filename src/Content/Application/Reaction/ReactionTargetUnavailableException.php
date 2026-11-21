<?php

declare(strict_types=1);

namespace App\Content\Application\Reaction;

/** The target does not exist or the user may not see it, so they cannot react to it. */
final class ReactionTargetUnavailableException extends \RuntimeException
{
}
