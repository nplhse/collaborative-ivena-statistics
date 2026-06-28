<?php

declare(strict_types=1);

namespace App\Shared\Application\Export;

final class ExportBlockedException extends \RuntimeException
{
    public function __construct(
        public readonly int $count,
        public readonly string $exporterKey,
    ) {
        parent::__construct(sprintf(
            'Export "%s" blocked: %d rows exceed the limit of %d.',
            $exporterKey,
            $count,
            ExportLimits::MAX_EXPORT_ROWS,
        ));
    }
}
