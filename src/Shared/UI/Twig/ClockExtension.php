<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig;

use Psr\Clock\ClockInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * The application clock in templates (ADR 021): `clock_now()` instead of `'now'|date`, which reads the system time
 * and ignores mockTime() in tests.
 *
 * @psalm-suppress UnusedClass Registered through #[AsTwigFunction].
 */
final readonly class ClockExtension
{
    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    /** @psalm-suppress PossiblyUnusedMethod Called from Twig. */
    #[AsTwigFunction('clock_now')]
    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }
}
