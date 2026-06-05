<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisEntityLabelResolver;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class GenericAnalysisEntityLabelResolverTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private GenericAnalysisEntityLabelResolver $resolver;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        UserFactory::createOne();
        $this->resolver = self::getContainer()->get(GenericAnalysisEntityLabelResolver::class);
    }

    public function testSupportsEntityDimensions(): void
    {
        self::assertTrue($this->resolver->supports('hospital'));
        self::assertTrue($this->resolver->supports('state'));
        self::assertTrue($this->resolver->supports('speciality'));
        self::assertTrue($this->resolver->supports('occasion'));
        self::assertFalse($this->resolver->supports('month'));
    }

    public function testResolveHospitalUsesLookup(): void
    {
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['name' => 'Resolver Hospital']);

        self::assertSame(
            [(int) $hospital->getId() => 'Resolver Hospital'],
            $this->resolver->resolve('hospital', [(int) $hospital->getId()]),
        );
    }

    public function testResolveStateReturnsNames(): void
    {
        $state = StateFactory::createOne(['name' => 'Resolver State', 'createdBy' => UserFactory::random()]);

        self::assertSame(
            [(int) $state->getId() => 'Resolver State'],
            $this->resolver->resolve('state', [(int) $state->getId()]),
        );
    }

    public function testResolveEmptyIdsReturnsEmptyArray(): void
    {
        self::assertSame([], $this->resolver->resolve('hospital', []));
    }

    public function testResolveUnknownDimensionReturnsEmptyArray(): void
    {
        self::assertSame([], $this->resolver->resolve('month', [1]));
    }

    public function testResolveUnknownIdIsOmitted(): void
    {
        self::assertSame([], $this->resolver->resolve('hospital', [9_999_999]));
    }

    public function testResolveSpecialityReturnsNames(): void
    {
        $speciality = SpecialityFactory::createOne(['name' => 'Resolver Speciality']);

        self::assertSame(
            [(int) $speciality->getId() => 'Resolver Speciality'],
            $this->resolver->resolve('speciality', [(int) $speciality->getId()]),
        );
    }

    public function testResolveOccasionReturnsNames(): void
    {
        $occasion = OccasionFactory::createOne(['name' => 'Resolver Occasion']);

        self::assertSame(
            [(int) $occasion->getId() => 'Resolver Occasion'],
            $this->resolver->resolve('occasion', [(int) $occasion->getId()]),
        );
    }
}
