<?php

namespace App\Service\Seed;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem('app.seed_provider')]
final class InfectionSeedProvider implements SeedProviderInterface
{
    #[\Override]
    public function provide(): \Generator
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

    #[\Override]
    public function getType(): string
    {
        return 'infection';
    }
}
