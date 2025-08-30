<?php

namespace App\Factory;

use App\Entity\Allocation;
use App\Enum\AllocationGender;
use App\Enum\AllocationTransportType;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<Allocation>
 */
final class AllocationFactory extends PersistentProxyObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return Allocation::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        $createdAt = \DateTimeImmutable::createFromMutable(self::faker()->dateTimeThisDecade());
        $arrivalAt = $createdAt->add(new \DateInterval('PT'.random_int(1, 60).'M'));

        return [
            'arrivalAt' => $arrivalAt,
            'createdAt' => $createdAt,
            'dispatchArea' => DispatchAreaFactory::random(),
            'state' => StateFactory::random(),
            'import' => ImportFactory::random(),
            'gender' => self::faker()->randomElement(AllocationGender::cases()),
            'age' => self::faker()->numberBetween(1, 99),
            'hospital' => HospitalFactory::random(),
            'isCPR' => self::faker()->boolean(),
            'isPregnant' => self::faker()->boolean(),
            'isShock' => self::faker()->boolean(),
            'isVentilated' => self::faker()->boolean(),
            'isWithPhysician' => self::faker()->boolean(),
            'requiresCathlab' => self::faker()->boolean(),
            'requiresResus' => self::faker()->boolean(),
            'transportType' => self::faker()->randomElement(AllocationTransportType::cases()),
        ];
    }
}
