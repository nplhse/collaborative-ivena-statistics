<?php

namespace App\Service\Seed;

use App\Entity\Address;
use App\Entity\Hospital;
use App\Entity\State;
use App\Entity\User;
use App\Enum\HospitalLocation;
use App\Enum\HospitalSize;
use App\Enum\HospitalTier;
use App\Service\Seed\Areas\AreaCache;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * @implements SeedProviderInterface<array{
 *     state: string,
 *     area: string,
 *     name: string,
 *     code?: string,
 *     tier: string,
 *     size: string,
 *     beds: int,
 *     location: string,
 *     address: array{
 *         street: string,
 *         city: string,
 *         state: string,
 *         postalCode: string,
 *         country: string
 *     }
 * }>
 */
#[AsTaggedItem('app.seed_provider', priority: 100)]
final class HospitalSeedProvider implements SeedProviderInterface
{
    public function __construct(
        private AreaCache $areaCache,
    ) {
    }

    /**
     * @return iterable<object>
     */
    #[\Override]
    public function build(User $user): iterable
    {
        $this->areaCache->warmUp();

        foreach ($this->provide() as $row) {
            if (!$this->areaCache->hasState($row['state'])) {
                throw new \RuntimeException("Unknown state '{$row['state']}' for hospital '{$row['name']}'");
            }

            if (!$this->areaCache->hasArea($row['state'], $row['area'])) {
                throw new \RuntimeException("Unknown area '{$row['area']}' in state '{$row['state']}' for hospital '{$row['name']}'");
            }

            $stateRef = $this->areaCache->getStateRef($row['state']);
            $areaRef = $this->areaCache->getAreaRef($row['state'], $row['area']);

            $address = new Address();
            $address->setStreet('123 Fake Street');
            $address->setCity('Fake City');
            $address->setState('State');
            $address->setPostalCode('12345');
            $address->setCountry('Deutschland');

            $hospital = new Hospital()
                ->setName($row['name'])
                ->setState($stateRef)
                ->setDispatchArea($areaRef)
                ->setTier(HospitalTier::from($row['tier']))
                ->setLocation(HospitalLocation::from($row['location']))
                ->setSize(HospitalSize::from($row['size']))
                ->setBeds($row['beds'])
                ->setAddress(
                    new Address()
                        ->setStreet($row['address']['street'])
                        ->setCity($row['address']['city'])
                        ->setPostalCode($row['address']['postalCode'])
                        ->setCountry($row['address']['country'])
                        ->setState($row['address']['state'])
                )
                ->setOwner($user)
                ->setCreatedBy($user);

            yield $hospital;
        }
    }

    /**
     * @return iterable<array{
     *     state: string,
     *     area: string,
     *     name: string,
     *     code?: string,
     *     tier: string,
     *     size: string,
     *     beds: int,
     *     location: string,
     *     address: array{
     *         street: string,
     *         city: string,
     *         state: string,
     *         postalCode: string,
     *         country: string
     *     }
     * }>
     */
    #[\Override]
    public function provide(): iterable
    {
        yield [
            'state' => 'Hessen',
            'area' => 'Frankfurt',
            'name' => 'Agaplesion Bethanien Krankenhaus',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 204,
            'location' => 'Urban',
            'address' => [
                'street' => 'Im Prüfling 21-25',
                'city' => 'Frankfurt am Main',
                'state' => 'Hessen',
                'postalCode' => '60389',
                'country' => 'Deutschland',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function purgeTables(): array
    {
        return ['hospital'];
    }

    #[\Override]
    public function getType(): string
    {
        return 'hospital';
    }
}
