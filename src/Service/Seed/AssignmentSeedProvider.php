<?php

namespace App\Service\Seed;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem('app.seed_provider')]
final class AssignmentSeedProvider implements SeedProviderInterface
{
    #[\Override]
    public function provide(): \Generator
    {
        yield 'Arzt/Arzt';
        yield 'Einweisung';
        yield 'LST';
        yield 'Notzuweisung';
        yield 'Patient';
        yield 'RD';
        yield 'ZLST';
    }

    #[\Override]
    public function getType(): string
    {
        return 'assignment';
    }
}
