<?php

declare(strict_types=1);

namespace App\Tests\Support\Encounter\Probe;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Fixed label of the test-only schema `probe`, so no catalogue needs a key for it.
 */
final readonly class ProbeLabel implements TranslatableInterface
{
    #[\Override]
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return 'Probe records (test)';
    }
}
