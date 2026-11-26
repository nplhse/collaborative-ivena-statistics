<?php

declare(strict_types=1);

namespace App\Content\Domain\Exception;

/**
 * Only a published, visible post that was not announced before can be announced (#1486).
 */
final class PostCannotBeAnnouncedException extends \DomainException
{
}
