<?php

declare(strict_types=1);

namespace App\Content\Domain\Enum;

/**
 * Content a reaction can belong to (#467). A new target needs a case here and a reaction target provider; the
 * reaction table stays as it is.
 */
enum ReactionTargetType: string
{
    case Post = 'post';
    case Comment = 'comment';
    case Activity = 'activity';
}
