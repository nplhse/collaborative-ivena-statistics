<?php

namespace App\Service\Seed;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * @implements SeedProviderInterface<string>
 */
#[AsTaggedItem('app.seed_provider')]
final class InfectionSeedProvider implements SeedProviderInterface
{
    /**
     * @return iterable<string>
     */
    #[\Override]
    public function provide(): iterable
    {
        yield '3MRGN';
        yield '4MRGN/CRE';
        yield 'HKLE';
        yield 'I-';
        yield 'I+';
        yield 'I+MR';
        yield 'I+NO';
        yield 'Influenza';
        yield 'Masern';
        yield 'MERS';
        yield 'MRSA';
        yield 'Mumps';
        yield 'Noro';
        yield 'Rota';
        yield 'Rötel';
        yield 'Sonstiges';
        yield 'TBC';
        yield 'V.a. COVID';
        yield 'Varizellen';
    }

    /**
     * @return non-empty-string
     */
    #[\Override]
    public function getType(): string
    {
        return 'infection';
    }
}
