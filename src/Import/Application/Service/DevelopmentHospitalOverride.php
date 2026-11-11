<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The explicit target hospital override for imports exists only in the `dev` environment (#878).
 *
 * It lets a developer load a real export into a fictional demo hospital. Outside `dev` the upload
 * form has no override field and a stored override flag is ignored, so the normal hospital checks apply.
 */
final readonly class DevelopmentHospitalOverride
{
    public function __construct(
        #[Autowire(param: 'kernel.environment')]
        private string $environment,
    ) {
    }

    public function isAvailable(): bool
    {
        return 'dev' === $this->environment;
    }
}
