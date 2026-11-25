<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Export;

/**
 * A query of an export run returned more rows or a larger file than allowed (ADR 045). The run fails; the query has to
 * be split or narrowed in a new version.
 */
final class ExportLimitExceeded extends \RuntimeException
{
}
