<?php

namespace App\Service\Seed;

use App\Entity\DispatchArea;
use App\Entity\State;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * @implements SeedProviderInterface<array{state:string, name:string}>
 */
#[AsTaggedItem('app.seed_provider', priority: 200)]
final class AreaSeedProvider implements SeedProviderInterface
{
    /**
     * @return iterable<object>
     */
    #[\Override]
    public function build(User $user): iterable
    {
        $rows = \iterator_to_array($this->provide(), false);

        /** @var array<string, State> $states */
        $states = [];
        foreach ($rows as $row) {
            $sName = $row['state'];
            if (!isset($states[$sName])) {
                $state = new State()
                    ->setName($sName)
                    ->setCreatedBy($user);
                $states[$sName] = $state;
                yield $state;
            }
        }

        foreach ($rows as $row) {
            $area = new DispatchArea()
                ->setName($row['name'])
                ->setState($states[$row['state']])
                ->setCreatedBy($user);

            yield $area;
        }
    }

    /**
     * @return \Generator<int, array{state:string, name:string}, mixed, void>
     */
    #[\Override]
    public function provide(): \Generator
    {
        yield ['state' => 'Hessen', 'name' => 'Bergstraße'];
        yield ['state' => 'Hessen', 'name' => 'Darmstadt'];
        yield ['state' => 'Hessen', 'name' => 'Darmstadt-Dieburg'];
        yield ['state' => 'Hessen', 'name' => 'Frankfurt'];
        yield ['state' => 'Hessen', 'name' => 'Fulda'];
        yield ['state' => 'Hessen', 'name' => 'Gießen'];
        yield ['state' => 'Hessen', 'name' => 'Groß-Gerau'];
        yield ['state' => 'Hessen', 'name' => 'Hersfeld-Rotenburg'];
        yield ['state' => 'Hessen', 'name' => 'Hochtaunus'];
        yield ['state' => 'Hessen', 'name' => 'Kassel'];
        yield ['state' => 'Hessen', 'name' => 'Lahn-Dill'];
        yield ['state' => 'Hessen', 'name' => 'Limburg-Weilburg'];
        yield ['state' => 'Hessen', 'name' => 'Main-Kinzig'];
        yield ['state' => 'Hessen', 'name' => 'Main-Taunus'];
        yield ['state' => 'Hessen', 'name' => 'Marburg-Biedenkopf'];
        yield ['state' => 'Hessen', 'name' => 'Odenwald'];
        yield ['state' => 'Hessen', 'name' => 'Offenbach'];
        yield ['state' => 'Hessen', 'name' => 'Rheingau Taunus'];
        yield ['state' => 'Hessen', 'name' => 'Schwalm-Eder'];
        yield ['state' => 'Hessen', 'name' => 'Vogelsberg'];
        yield ['state' => 'Hessen', 'name' => 'Waldeck-Frankenberg'];
        yield ['state' => 'Hessen', 'name' => 'Werra-Meißner'];
        yield ['state' => 'Hessen', 'name' => 'Wetterau'];
        yield ['state' => 'Hessen', 'name' => 'Wiesbaden'];
        yield ['state' => 'Bayern', 'name' => 'Bayerischer Untermain'];
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function purgeTables(): array
    {
        return ['dispatch_area', 'state'];
    }

    #[\Override]
    public function getType(): string
    {
        return 'area';
    }
}
