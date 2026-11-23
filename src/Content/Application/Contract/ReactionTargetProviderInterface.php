<?php

declare(strict_types=1);

namespace App\Content\Application\Contract;

use App\Content\Application\Reaction\ReactionNotificationTarget;
use App\Content\Domain\Enum\ReactionTargetType;
use App\User\Domain\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Knows one kind of content that can receive reactions (#467): which targets a viewer may see and which still exist.
 * Reactions follow the visibility of their target, so a reaction never reveals content the viewer cannot open.
 */
#[AutoconfigureTag(self::TAG)]
interface ReactionTargetProviderInterface
{
    public const string TAG = 'app.content.reaction_target';

    public function targetType(): ReactionTargetType;

    /**
     * @param list<int> $ids
     *
     * @return list<int> the ids the viewer may see (null: not signed in)
     */
    public function visibleIds(array $ids, ?User $viewer): array;

    /**
     * @param list<int> $ids
     *
     * @return list<int> the ids whose content still exists, visible or not
     */
    public function existingIds(array $ids): array;

    /** Owner, name and link of a target for its reaction notification (#973); null when it no longer exists. */
    public function notificationTarget(int $id): ?ReactionNotificationTarget;
}
