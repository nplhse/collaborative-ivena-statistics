<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Catalog;

/**
 * The installed catalog cannot serve the generator: a name or PZC code is missing or ambiguous.
 */
final class CatalogMismatchException extends \RuntimeException
{
}
