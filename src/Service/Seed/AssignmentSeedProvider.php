<?php

namespace App\Service\Seed;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * @implements SeedProviderInterface<string>
 */
#[AsTaggedItem('app.seed_provider')]
final class AssignmentSeedProvider implements SeedProviderInterface
{
    /**
     * @return iterable<string>
     */
    #[\Override]
    public function provide(): iterable
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
