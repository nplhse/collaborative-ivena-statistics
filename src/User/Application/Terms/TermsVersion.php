<?php

declare(strict_types=1);

namespace App\User\Application\Terms;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The configured version of the terms of use (#1564, ADR 043): `app.terms.version`, raised by hand for a material
 * change. Without a version an acceptance is stored without one, and nobody is asked again at login.
 */
final readonly class TermsVersion
{
    /** @psalm-suppress PossiblyUnusedMethod Built by the container. */
    public function __construct(
        #[Autowire(param: 'app.terms.version')]
        private ?string $version = null,
    ) {
    }

    public function current(): ?string
    {
        return $this->version;
    }
}
