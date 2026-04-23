<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\MciCaseFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Content\Infrastructure\Factory\PostCategoryFactory;
use App\Content\Infrastructure\Factory\PostCommentFactory;
use App\Content\Infrastructure\Factory\PostFactory;
use App\Content\Infrastructure\Factory\PostTagFactory;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Factory\UserFactory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class AppFixtures extends Fixture
{
    #[\Override]
    public function load(ObjectManager $manager): void
    {
        UserFactory::createMany(5);

        StateFactory::createMany(3);
        DispatchAreaFactory::createMany(49);
        $dispatchArea = DispatchAreaFactory::createOne(
            ['name' => 'Test Area'])
        ;

        HospitalFactory::createMany(9);
        $hospital = HospitalFactory::createOne([
            'name' => 'Test Hospital',
            'dispatchArea' => $dispatchArea,
        ]);

        ImportFactory::createMany(14);
        ImportFactory::createOne([
            'name' => 'Test Import',
            'hospital' => $hospital,
            'status' => ImportStatus::PENDING,
        ]
        );

        DepartmentFactory::createMany(5);
        SpecialityFactory::createMany(10);

        AssignmentFactory::createMany(5);
        InfectionFactory::createMany(10);
        OccasionFactory::createMany(5);

        IndicationRawFactory::createMany(25);
        IndicationNormalizedFactory::createMany(20);

        AllocationFactory::createMany(100);
        MciCaseFactory::createMany(8);

        PostCategoryFactory::createOne();
        PostCategoryFactory::createOne();

        PostTagFactory::createOne();
        PostTagFactory::createOne();
        PostTagFactory::createOne();

        PostFactory::createMany(15);

        PostCommentFactory::createMany(6);

        $manager->flush();
    }

    /**
     * @return list<class-string<FixtureInterface>>
     */
    public function getDependencies(): array
    {
        return [UserFixtures::class];
    }
}
