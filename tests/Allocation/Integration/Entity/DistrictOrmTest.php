<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Entity;

use App\Allocation\Domain\Entity\District;
use App\Allocation\Domain\Entity\DistrictGeometry;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Repository\DistrictRepository;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class DistrictOrmTest extends KernelTestCase
{
    use Factories;

    public function testStoresADistrictWithoutUserAndWithItsGeometry(): void
    {
        $em = $this->entityManager();
        $district = new District('06411', 'Darmstadt');
        $geometry = new DistrictGeometry($district);
        $geometry->storeGeometry('{"type":"Polygon","coordinates":[]}', 'test', null, null, null);
        $em->persist($district);
        $em->persist($geometry);
        $em->flush();
        $id = $district->getId();
        $em->clear();

        $stored = $em->find(District::class, $id);
        self::assertInstanceOf(District::class, $stored);
        self::assertNull($stored->getCreatedBy());
        self::assertNotNull($stored->getPublicId());
        self::assertSame(
            '{"type":"Polygon","coordinates":[]}',
            self::getContainer()->get(DistrictRepository::class)->findGeometry($stored)?->getGeometry(),
        );
    }

    public function testAgsIsUnique(): void
    {
        $em = $this->entityManager();
        $em->persist(new District('06411', 'Darmstadt'));
        $em->persist(new District('06411', 'Duplicate'));

        $this->expectException(UniqueConstraintViolationException::class);

        $em->flush();
    }

    public function testDatabaseRejectsAStateKeyThatDoesNotMatchTheAgs(): void
    {
        $em = $this->entityManager();
        $district = new District('06411', 'Darmstadt');
        $em->persist($district);
        $em->flush();

        $this->expectException(DriverException::class);

        $em->getConnection()->executeStatement('UPDATE district SET state_code = ? WHERE id = ?', ['09', $district->getId()]);
    }

    public function testDeletingADispatchAreaKeepsItsDistrict(): void
    {
        $em = $this->entityManager();
        $area = DispatchAreaFactory::createOne();
        $district = new District('06411', 'Darmstadt')->setDispatchArea($area);
        $em->persist($district);
        $em->flush();
        $districtId = $district->getId();
        $areaId = $area->getId();
        $em->clear();

        $em->getConnection()->executeStatement('DELETE FROM dispatch_area WHERE id = ?', [$areaId]);

        $stored = $em->find(District::class, $districtId);
        self::assertInstanceOf(District::class, $stored);
        self::assertNull($stored->getDispatchArea());
    }

    public function testDeletingADistrictDeletesItsGeometry(): void
    {
        $em = $this->entityManager();
        $district = new District('06411', 'Darmstadt');
        $em->persist($district);
        $em->persist(new DistrictGeometry($district));
        $em->flush();
        $id = $district->getId();

        $em->getConnection()->executeStatement('DELETE FROM district WHERE id = ?', [$id]);

        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM district_geometry WHERE district_id = ?', [$id]));
    }

    private function entityManager(): EntityManagerInterface
    {
        self::bootKernel();

        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
