<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Hospital\PreparedLocation;

use App\Allocation\Application\Contracts\HospitalIsochroneStoreInterface;
use App\Allocation\Application\Hospital\IsochroneOrigin;
use App\Allocation\Application\Hospital\PreparedLocation\PreparedLocation;
use App\Allocation\Application\Hospital\PreparedLocation\PreparedLocationApplier;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\State;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Cases the database-backed command test does not reach; see PreparedLocationCommandsTest for the full flow.
 */
final class PreparedLocationApplierTest extends TestCase
{
    /**
     * @return iterable<string, array{Hospital}>
     */
    public static function hospitalsWithoutIsochronePath(): iterable
    {
        yield 'no state' => [new Hospital()->setName('Ohne Land')->setPublicId(Uuid::v4())];
        yield 'state not persisted' => [new Hospital()->setName('Neues Land')->setState(new State()->setName('Hessen'))->setPublicId(Uuid::v4())];
    }

    #[DataProvider('hospitalsWithoutIsochronePath')]
    public function testHospitalWithoutStateOrPublicIdIsRefusedBeforeAnyWrite(Hospital $hospital): void
    {
        $isochroneStore = $this->createMock(HospitalIsochroneStoreInterface::class);
        $isochroneStore->expects(self::never())->method('writeForHospital');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $report = $this->applier($isochroneStore, $entityManager)->run('musterstadt', $hospital, apply: true, force: true);

        self::assertFalse($report->success);
        self::assertStringContainsString('needs a federal state and a public id', (string) $report->error);
        self::assertNull($hospital->getLatitude());
    }

    private function applier(HospitalIsochroneStoreInterface $isochroneStore, EntityManagerInterface $entityManager): PreparedLocationApplier
    {
        $store = new InMemoryPreparedLocationStore();
        $store->save(new PreparedLocation(
            slug: 'musterstadt',
            label: 'Musterstadt',
            address: null,
            latitude: 50.5,
            longitude: 8.25,
            isochrones: IsochroneOrigin::withCoordinates([
                'type' => 'FeatureCollection',
                'features' => [
                    ['type' => 'Feature', 'properties' => ['value' => 600], 'geometry' => ['type' => 'Polygon', 'coordinates' => []]],
                ],
            ], 50.5, 8.25),
            preparedAt: new \DateTimeImmutable('2026-10-28T12:00:00+00:00'),
        ));

        return new PreparedLocationApplier($store, $isochroneStore, $entityManager);
    }
}
