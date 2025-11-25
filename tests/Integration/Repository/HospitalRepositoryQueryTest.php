<?php

// tests/Integration/Repository/HospitalRepositoryQueryTest.php
declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Hospital;
use App\Factory\DispatchAreaFactory;
use App\Factory\HospitalFactory;
use App\Factory\StateFactory;
use App\Repository\HospitalRepository;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class HospitalRepositoryQueryTest extends KernelTestCase
{
    private HospitalRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->repo = $container->get(HospitalRepository::class);
    }

    public function testAdminSeesAllHospitals(): void
    {
        $admin = UserFactory::createOne(['roles' => ['ROLE_ADMIN']]);

        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createMany(3);

        $qb = $this->repo->getQueryBuilderForAccessibleHospitals($admin);
        $result = $qb->getQuery()->getResult();

        self::assertGreaterThanOrEqual(3, \count($result));
        self::assertContainsOnlyInstancesOf(Hospital::class, $result);
    }

    public function testRegularUsersSeeOnlyOwnedHospitals(): void
    {
        $owner = UserFactory::createOne();
        $other = UserFactory::createOne();

        StateFactory::createOne();
        DispatchAreaFactory::createOne();

        $owned1 = HospitalFactory::createOne(['owner' => $owner]);
        $owned2 = HospitalFactory::createOne(['owner' => $owner]);
        $foreign = HospitalFactory::createOne(['owner' => $other]);

        $qb = $this->repo->getQueryBuilderForAccessibleHospitals($owner);
        /** @var Hospital[] $result */
        $result = $qb->getQuery()->getResult();

        $ids = array_map(static fn (Hospital $h) => $h->getId(), $result);
        self::assertContains($owned1->getId(), $ids);
        self::assertContains($owned2->getId(), $ids);
        self::assertNotContains($foreign->getId(), $ids);
    }
}
