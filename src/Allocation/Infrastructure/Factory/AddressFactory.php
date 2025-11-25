<?php

namespace App\Allocation\Infrastructure\Factory;

use App\Allocation\Domain\Entity\Address;
use Zenstruck\Foundry\ObjectFactory;

/**
 * @extends ObjectFactory<Address>
 */
final class AddressFactory extends ObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return Address::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        return [
            'city' => self::faker()->city(),
            'state' => \Faker\Provider\de_DE\Address::state(),
            'country' => 'Deutschland',
            'postalCode' => self::faker()->postcode(),
            'street' => self::faker()->streetAddress(),
        ];
    }
}
