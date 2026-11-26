<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig;

use App\Shared\Infrastructure\Maintenance\MaintenanceMode;
use Twig\Attribute\AsTwigFunction;

/**
 * `maintenance_since()` for the banner that administrators see while the maintenance mode is on (#1680, ADR 046).
 *
 * @psalm-suppress UnusedClass Registered through #[AsTwigFunction].
 */
final readonly class MaintenanceExtension
{
    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private MaintenanceMode $maintenanceMode,
    ) {
    }

    /** @psalm-suppress PossiblyUnusedMethod Called from Twig. */
    #[AsTwigFunction('maintenance_since')]
    public function since(): ?\DateTimeImmutable
    {
        return $this->maintenanceMode->enabledSince();
    }
}
