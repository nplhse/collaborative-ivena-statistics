<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Infrastructure\Doctrine;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Doctrine\OriginalEntityUser;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;

final class OriginalEntityUserTest extends DatabaseKernelTestCase
{
    public function testOriginalOwnerIsReadFromUnitOfWorkBeforeFlush(): void
    {
        $previous = UserFactory::createOne();
        $next = UserFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $previous, 'name' => 'Owner Klinik']);

        $hospitalId = $hospital->getId();
        self::assertNotNull($hospitalId);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $hospital = $entityManager->find(Hospital::class, $hospitalId);
        self::assertNotNull($hospital);

        $hospital->setName('Renamed Klinik');
        self::assertSame($previous->getId(), OriginalEntityUser::from($entityManager, $hospital, 'owner')?->getId());

        $hospital->setOwner($next);
        self::assertSame($previous->getId(), OriginalEntityUser::from($entityManager, $hospital, 'owner')?->getId());
        self::assertSame($next->getId(), $hospital->getOwner()?->getId());
    }
}
