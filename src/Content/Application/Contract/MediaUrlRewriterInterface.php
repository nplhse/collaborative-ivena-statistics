<?php

declare(strict_types=1);

namespace App\Content\Application\Contract;

use App\Content\Application\Media\MediaUrlRewriteResult;

/**
 * Replaces old media URLs `/uploads/media/<filename>` in stored content with the URLs of the moved files (ADR 039).
 */
interface MediaUrlRewriterInterface
{
    public function rewrite(bool $dryRun): MediaUrlRewriteResult;
}
