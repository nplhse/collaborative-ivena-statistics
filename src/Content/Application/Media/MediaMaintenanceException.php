<?php

declare(strict_types=1);

namespace App\Content\Application\Media;

/**
 * A maintenance task on media files cannot start, for example because a backup or restore holds the files (ADR 039).
 */
final class MediaMaintenanceException extends \RuntimeException
{
}
