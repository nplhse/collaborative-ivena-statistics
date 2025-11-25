<?php

namespace App\Seed\Infrastructure\Provider;

use App\Allocation\Domain\Entity\Occasion;
use App\Seed\Application\Contracts\SeedProviderInterface;
use App\User\Domain\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * @implements SeedProviderInterface<string>
 */
#[AsTaggedItem('app.seed_provider')]
final class OccasionSeedProvider implements SeedProviderInterface
{
    /**
     * @return iterable<Occasion>
     */
    #[\Override]
    public function build(User $user): iterable
    {
        foreach ($this->provide() as $name) {
            $entity = new Occasion()
                ->setName($name)
                ->setCreatedBy($user);

            yield $entity;
        }
    }

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
     * @return list<string>
     */
    #[\Override]
    public function purgeTables(): array
    {
        return ['occasion'];
    }

    #[\Override]
    public function getType(): string
    {
        return 'occasion';
    }
}
