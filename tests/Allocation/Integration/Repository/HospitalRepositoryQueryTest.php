<?php

// tests/Integration/Repository/HospitalRepositoryQueryTest.php
declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Repository;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class HospitalRepositoryQueryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;
    private HospitalRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->repo = $container->get(HospitalRepository::class);
    }

    public function testAdminSeesAllHospitals(): void
    {
        $admin = UserFactory::createOne([
            'roles' => ['ROLE_ADMIN'],
            'username' => 'admin-'.bin2hex(random_bytes(6)),
        ]);

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
        $owner = UserFactory::createOne(['username' => 'owner-'.bin2hex(random_bytes(6))]);
        $other = UserFactory::createOne(['username' => 'other-'.bin2hex(random_bytes(6))]);

        StateFactory::createOne();
        DispatchAreaFactory::createOne();

        $owned1 = HospitalFactory::createOne(['owner' => $owner]);
        $owned2 = HospitalFactory::createOne(['owner' => $owner]);
        $foreign = HospitalFactory::createOne(['owner' => $other]);

        $qb = $this->repo->getQueryBuilderForAccessibleHospitals($owner);
        /** @var Hospital[] $result */
        $result = $qb->getQuery()->getResult();

        $ids = array_map(static fn (Hospital $h): ?int => $h->getId(), $result);
        self::assertContains($owned1->getId(), $ids);
        self::assertContains($owned2->getId(), $ids);
        self::assertNotContains($foreign->getId(), $ids);
    }

    public function testFindNamesByIdsReturnsIdToNameMap(): void
    {
        UserFactory::createOne();
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['name' => 'Lookup Hospital']);

        self::assertSame(
            [(int) $hospital->getId() => 'Lookup Hospital'],
            $this->repo->findNamesByIds([(int) $hospital->getId()]),
        );
    }

    public function testFindNamesByIdsWithEmptyListReturnsEmptyArray(): void
    {
        self::assertSame([], $this->repo->findNamesByIds([]));
    }

    public function testAdminSeesOnlyParticipatingHospitalsInParticipatingSummaries(): void
    {
        $admin = UserFactory::createOne([
            'roles' => ['ROLE_ADMIN'],
            'username' => 'admin-'.bin2hex(random_bytes(6)),
        ]);

        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $participating = HospitalFactory::createOne();
        $nonParticipating = HospitalFactory::createOne();
        $nonParticipating->setParticipating(false);
        $nonParticipating->_save();

        $summaries = $this->repo->findAccessibleParticipatingHospitalSummaries($admin);
        $ids = array_column($summaries, 'id');

        self::assertContains((int) $participating->getId(), $ids);
        self::assertCount(1, $summaries);
    }

    public function testOwnerSeesOnlyOwnedParticipatingHospitalsInParticipatingSummaries(): void
    {
        $owner = UserFactory::createOne(['username' => 'owner-'.bin2hex(random_bytes(6))]);
        $other = UserFactory::createOne(['username' => 'other-'.bin2hex(random_bytes(6))]);

        StateFactory::createOne();
        DispatchAreaFactory::createOne();

        $participating = HospitalFactory::createOne(['owner' => $owner]);
        $nonParticipating = HospitalFactory::createOne(['owner' => $owner]);
        $nonParticipating->setParticipating(false);
        $nonParticipating->_save();
        HospitalFactory::createOne(['owner' => $other]);

        $summaries = $this->repo->findAccessibleParticipatingHospitalSummaries($owner);
        $ids = array_column($summaries, 'id');

        self::assertSame([(int) $participating->getId()], $ids);
    }
}
