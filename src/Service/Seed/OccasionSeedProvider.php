<?php

namespace App\Service\Seed;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * @implements SeedProviderInterface<string>
 */
#[AsTaggedItem('app.seed_provider')]
final class OccasionSeedProvider implements SeedProviderInterface
{
    /**
     * @return iterable<string>
     */
    #[\Override]
    public function provide(): iterable
    {
        yield 'Arbeitsunfall';
        yield 'aus Arztpraxis';
        yield 'Bahnunfall';
        yield 'Diagnostik';
        yield 'Fluggeräteunfall';
        yield 'Häuslicher Einsatz';
        yield 'Hausunfall';
        yield 'Intensivstation';
        yield 'Intervention';
        yield 'k.A.';
        yield 'Öffentlicher Raum';
        yield 'OP';
        yield 'Schiff / Bootunfall';
        yield 'Schussverletzung';
        yield 'Sekundärverlegung';
        yield 'Sonstiger Einsatz';
        yield 'Sportunfall';
        yield 'Stich- / Schnittverletzung';
        yield 'Stichverletzung';
        yield 'Sturz < 3m Höhe';
        yield 'Sturz > 3m Höhe';
        yield 'Unfall > 4 Verletzte';
        yield 'Unfall 2 bis 4 Verletzte';
        yield 'Unfall eingeklemmte Pers.';
        yield 'Verkehrsunfall';
        yield 'VU mit Fußgänger';
        yield 'VU mit Zweirad';
        yield 'VU Verletzte Person';
        yield 'Weaning';
    }

    /**
     * @return non-empty-string
     */
    #[\Override]
    public function getType(): string
    {
        return 'occasion';
    }
}
