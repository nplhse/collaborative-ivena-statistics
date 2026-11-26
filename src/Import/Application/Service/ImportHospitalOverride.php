<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Where the explicit target hospital override for imports exists: `dev` and `demo` (#878, #1352).
 *
 * It lets a real export be loaded into a fictional demo hospital. In every other environment the upload
 * form has no override field and a stored override flag is ignored, so the normal hospital checks apply.
 * Who may use it is decided by {@see \App\Import\Infrastructure\Security\Voter\ImportCreateVoter::OPTION_HOSPITAL_OVERRIDE}.
 */
final readonly class ImportHospitalOverride
{
    private const array ENVIRONMENTS = ['dev', 'demo'];

    public function __construct(
        #[Autowire(param: 'kernel.environment')]
        private string $environment,
    ) {
    }

    public function isAvailable(): bool
    {
        return \in_array($this->environment, self::ENVIRONMENTS, true);
    }
}
