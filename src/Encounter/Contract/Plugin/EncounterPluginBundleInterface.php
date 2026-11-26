<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Plugin;

/**
 * Marks a Symfony bundle that contributes encounter schemas (ADR 037). Encounter imports the bundle's
 * `config/routes.php` (if it has one), so enabling the bundle in `config/bundles.php` needs no route file in
 * the application. The bundle registers its services, Doctrine mapping and migrations path itself.
 */
interface EncounterPluginBundleInterface
{
}
