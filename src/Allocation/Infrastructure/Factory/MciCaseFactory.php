<?php

namespace App\Allocation\Infrastructure\Factory;

use App\Allocation\Domain\Entity\MciCase;
use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Import\Infrastructure\Factory\ImportFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<MciCase>
 */
final class MciCaseFactory extends PersistentProxyObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return MciCase::class;
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
            'hospital' => HospitalFactory::random(),

            'mciId' => self::faker()->sha1(),
            'mciTitle' => self::faker()->sentence(3),

            'gender' => self::faker()->boolean(70) ? self::faker()->randomElement(AllocationGender::cases()) : null,
            'age' => self::faker()->boolean(70) ? self::faker()->numberBetween(1, 99) : null,

            'requiresResus' => self::faker()->boolean(60) ? self::faker()->boolean() : null,
            'requiresCathlab' => self::faker()->boolean(60) ? self::faker()->boolean() : null,

            'isCPR' => self::faker()->boolean(60) ? self::faker()->boolean() : null,
            'isVentilated' => self::faker()->boolean(60) ? self::faker()->boolean() : null,
            'isShock' => self::faker()->boolean(60) ? self::faker()->boolean() : null,
            'isPregnant' => self::faker()->boolean(60) ? self::faker()->boolean() : null,
            'isWithPhysician' => self::faker()->boolean(60) ? self::faker()->boolean(13) : null,

            'transportType' => self::faker()->boolean(70) ? self::faker()->randomElement(AllocationTransportType::cases()) : null,
            'urgency' => self::faker()->boolean(70) ? self::faker()->randomElement(AllocationUrgency::cases()) : null,

            'speciality' => self::faker()->boolean(50) ? SpecialityFactory::random() : null,
            'department' => self::faker()->boolean(50) ? DepartmentFactory::random() : null,
            'departmentWasClosed' => self::faker()->boolean(40) ? self::faker()->boolean() : null,

            'occasion' => self::faker()->boolean(95) ? OccasionFactory::random() : null,
            'infection' => self::faker()->boolean(10) ? InfectionFactory::random() : null,

            'indicationRaw' => self::faker()->boolean(70) ? IndicationRawFactory::random() : null,
            'indicationNormalized' => self::faker()->boolean(70) ? IndicationNormalizedFactory::random() : null,
        ];
    }
}
