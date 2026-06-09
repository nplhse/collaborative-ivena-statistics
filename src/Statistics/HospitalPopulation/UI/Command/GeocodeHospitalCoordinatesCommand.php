<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\UI\Command;

use App\Allocation\Domain\Entity\Hospital;
use App\Statistics\HospitalPopulation\Infrastructure\Geocoding\HospitalPopulationGeocodingLookupFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:hospital-population:geocode',
    description: 'Fill missing hospital coordinates from static geocoding lookup',
)]
final class GeocodeHospitalCoordinatesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HospitalPopulationGeocodingLookupFactory $geocodingLookupFactory,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
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

            if (!$coordinates instanceof \App\Statistics\HospitalPopulation\Infrastructure\Geocoding\HospitalPopulationCoordinates) {
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
