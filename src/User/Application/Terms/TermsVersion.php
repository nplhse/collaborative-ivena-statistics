<?php

declare(strict_types=1);

namespace App\User\Application\Terms;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The current version of the terms of use (#1564, ADR 043): `APP_TERMS_VERSION` through `app.terms.version`, raised
 * for a material change. Without a version an acceptance is stored without one, and nobody is asked at login.
 */
final readonly class TermsVersion
{
    private const string FORMAT = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/';

    private ?string $version;

    /** @psalm-suppress PossiblyUnusedMethod Built by the container. */
    public function __construct(
        #[Autowire(param: 'app.terms.version')]
        ?string $version = null,
    ) {
        $version = null === $version ? '' : trim($version);
        if ('' !== $version && 1 !== preg_match(self::FORMAT, $version)) {
            throw new \InvalidArgumentException(\sprintf('APP_TERMS_VERSION "%s" must be 1 to 32 letters, digits, dots, dashes or underscores.', $version));
        }

        $this->version = '' === $version ? null : $version;
    }

    public function current(): ?string
    {
        return $this->version;
    }
}
