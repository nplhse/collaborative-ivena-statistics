<?php

namespace App\Service\Seed;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.seed_provider')]
interface SeedProviderInterface
{
    /**
     * @return \Generator<string>
     */
    public function provide(): \Generator;

    public function getType(): string;
}
