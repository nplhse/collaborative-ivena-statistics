<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\UI\Command;

use App\Allocation\Domain\Entity\Hospital;
use App\Statistics\HospitalPopulation\Infrastructure\Geocoding\HospitalPopulationCoordinates;
use App\Statistics\HospitalPopulation\Infrastructure\Geocoding\HospitalPopulationGeocodingLookupFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:hospital-population:geocode',
    description: 'Fill missing hospital coordinates from static geocoding lookup',
)]
final readonly class GeocodeHospitalCoordinatesCommand
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private HospitalPopulationGeocodingLookupFactory $geocodingLookupFactory,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $lookup = $this->geocodingLookupFactory->create();

        /** @var list<Hospital> $hospitals */
        $hospitals = $this->entityManager->createQueryBuilder()
            ->select('h')
            ->from(Hospital::class, 'h')
            ->andWhere('h.latitude IS NULL OR h.longitude IS NULL')
            ->getQuery()
            ->getResult();

        $updated = 0;
        $skipped = 0;

        foreach ($hospitals as $hospital) {
            $coordinates = $lookup->resolve(
                $hospital->getAddress()->getPostalCode(),
                $hospital->getAddress()->getCity(),
                $hospital->getDispatchArea()?->getName(),
            );

            if (!$coordinates instanceof HospitalPopulationCoordinates) {
                ++$skipped;
                continue;
            }

            $hospital
                ->setLatitude($coordinates->latitude)
                ->setLongitude($coordinates->longitude);
            ++$updated;
        }

        $this->entityManager->flush();

        $io->success(sprintf('Updated %d hospitals, skipped %d without lookup match.', $updated, $skipped));

        return Command::SUCCESS;
    }
}
