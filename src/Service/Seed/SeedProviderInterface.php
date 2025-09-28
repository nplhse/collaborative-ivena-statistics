<?php

namespace App\Service\Seed;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * @template TValue
 */
#[AutoconfigureTag('app.seed_provider')]
interface SeedProviderInterface
{
    /**
     * @return iterable<TValue>
     */
    public function provide(): iterable;

    /** @return non-empty-string */
    public function getType(): string;
}
