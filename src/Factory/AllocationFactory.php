<?php

namespace App\Factory;

use App\Entity\Allocation;
use PHPUnit\Framework\Attributes\CoversNothing;
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
            'arrivalAt' => $createdAt,
            'createdAt' => $arrivalAt,
            'dispatchArea' => DispatchAreaFactory::random(),
            'gender' => self::faker()->randomElement(['M', 'W', 'D']),
            'hospital' => HospitalFactory::random(),
            'isCPR' => self::faker()->boolean(),
            'isPregnant' => self::faker()->boolean(),
            'isShock' => self::faker()->boolean(),
            'isVentilated' => self::faker()->boolean(),
            'isWithPhysician' => self::faker()->boolean(),
            'requiresCathlab' => self::faker()->boolean(),
            'requiresResus' => self::faker()->boolean(),
            'state' => StateFactory::random(),
        ];
    }
}
