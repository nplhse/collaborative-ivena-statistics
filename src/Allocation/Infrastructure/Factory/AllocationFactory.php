<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Factory;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Import\Infrastructure\Factory\ImportFactory;
use Zenstruck\Foundry\LazyValue;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Allocation>
 */
final class AllocationFactory extends PersistentObjectFactory
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
            'dispatchArea' => LazyValue::new(static fn (): object => DispatchAreaFactory::random()),
            'state' => LazyValue::new(static fn (): object => StateFactory::random()),
            'import' => LazyValue::new(static fn (): object => ImportFactory::random()),
            'gender' => self::faker()->randomElement(AllocationGender::cases()),
            'age' => self::faker()->numberBetween(1, 99),
            'hospital' => LazyValue::new(static fn (): object => HospitalFactory::random()),
            'isCPR' => self::faker()->boolean(),
            'isPregnant' => self::faker()->boolean(),
            'isShock' => self::faker()->boolean(),
            'isVentilated' => self::faker()->boolean(),
            'isWithPhysician' => self::faker()->boolean(13),
            'isWorkAccident' => self::faker()->boolean(8),
            'requiresCathlab' => self::faker()->boolean(1),
            'requiresResus' => self::faker()->boolean(5),
            'transportType' => self::faker()->randomElement(AllocationTransportType::cases()),
            'urgency' => self::faker()->randomElement(AllocationUrgency::cases()),
            'speciality' => LazyValue::new(static fn (): object => SpecialityFactory::random()),
            'department' => LazyValue::new(static fn (): object => DepartmentFactory::random()),
            'departmentWasClosed' => self::faker()->boolean(),
            'assignment' => LazyValue::new(static fn (): object => AssignmentFactory::random()),
            'infection' => LazyValue::new(fn (): ?object => self::faker()->boolean(10) ? InfectionFactory::randomOrCreate() : null),
            'occasion' => LazyValue::new(fn (): ?object => self::faker()->boolean(95) ? OccasionFactory::randomOrCreate() : null),
            'secondaryTransport' => LazyValue::new(fn (): ?object => self::faker()->boolean(20) ? SecondaryTransportFactory::randomOrCreate() : null),
            'indicationRaw' => LazyValue::new(static fn (): object => IndicationRawFactory::random()),
            'indicationNormalized' => LazyValue::new(fn (): ?object => self::faker()->boolean(90) ? IndicationNormalizedFactory::randomOrCreate() : null),
            'secondaryIndicationRaw' => null,
            'secondaryIndicationNormalized' => null,
            'assessment' => LazyValue::new(fn (): ?object => self::faker()->boolean(10) ? AssessmentFactory::createOne() : null),
        ];
    }
}
