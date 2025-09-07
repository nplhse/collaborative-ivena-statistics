<?php

namespace App\Service\Seed;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem('app.seed_provider')]
final class SpecialitySeedProvider implements SeedProviderInterface
{
    #[\Override]
    public function provide(): \Generator
    {
        yield 'Augenheilkunde';
        yield 'Chirurgie';
        yield 'Diagnostik/Geräte';
        yield 'Frauenheilkunde u. Geburtshilfe';
        yield 'Geriatrie';
        yield 'Hals-Nasen-Ohrenheilkunde';
        yield 'Haut- und Geschlechtskrankheiten';
        yield 'Herzchirurgie';
        yield 'Innere Medizin';
        yield 'Interdisziplinär';
        yield 'Kinder- und Jugendmedizin';
        yield 'Mund-Kiefer-Gesichtschirurgie';
        yield 'Neurochirurgie';
        yield 'Neurologie';
        yield 'Nuklearmedizin/Hämatologie/Onkologie';
        yield 'Psychiatrie und Psychotherapie';
        yield 'Sonderlage Ukraine';
        yield 'Strahlentherapie';
        yield 'Urologie';
        yield 'Zentrale Notaufnahme';
    }

    #[\Override]
    public function getType(): string
    {
        return 'speciality';
    }
}
