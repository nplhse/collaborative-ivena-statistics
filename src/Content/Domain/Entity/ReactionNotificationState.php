<?php

declare(strict_types=1);

namespace App\Content\Domain\Entity;

use App\Content\Domain\Enum\ReactionTargetType;
use Doctrine\ORM\Mapping as ORM;

/**
 * Where the grouped reaction notification of a target stands (#973). Kept apart from the entry, which is deleted some
 * time after it was read: the boundary must survive it, or the next reaction would report all earlier ones as new.
 * Reaction ids mark the boundary because timestamps only have whole seconds. Written via DBAL by the handler.
 *
 * @psalm-suppress ClassMustBeFinal
 * @psalm-suppress UnusedClass Doctrine-mapped; read and written via DBAL.
 * @psalm-suppress UnusedProperty Doctrine-mapped; read and written via DBAL.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'reaction_notification_state')]
class ReactionNotificationState
{
    #[ORM\Id]
    #[ORM\Column(length: 32, enumType: ReactionTargetType::class)]
    private ReactionTargetType $targetType;

    #[ORM\Id]
    #[ORM\Column]
    private int $targetId;

    /** The group's inbox entry; no foreign key, as it belongs to the Notification context and may be deleted there. */
    #[ORM\Column(nullable: true)]
    private ?int $notificationId = null;

    /** The current group counts reactions with a higher id. */
    #[ORM\Column]
    private int $floorReactionId = 0;

    /** Highest reaction id handled so far. */
    #[ORM\Column]
    private int $throughReactionId = 0;

    /** @psalm-suppress PossiblyUnusedMethod Rows are created via DBAL; the constructor keeps the mapping complete. */
    public function __construct(ReactionTargetType $targetType, int $targetId)
    {
        $this->targetType = $targetType;
        $this->targetId = $targetId;
    }
}
