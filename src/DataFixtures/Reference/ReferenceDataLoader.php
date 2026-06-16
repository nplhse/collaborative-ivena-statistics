<?php

declare(strict_types=1);

namespace App\DataFixtures\Reference;

use App\Allocation\Domain\Entity\Address;
use App\Allocation\Domain\Entity\Assignment;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Domain\Entity\Occasion;
use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Allocation\Domain\Entity\Speciality;
use App\Allocation\Domain\Entity\State;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Import\Infrastructure\Indication\IndicationKey;
use App\Statistics\HospitalPopulation\Infrastructure\Geocoding\HospitalPopulationCoordinates;
use App\Statistics\HospitalPopulation\Infrastructure\Geocoding\HospitalPopulationGeocodingLookupFactory;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ReferenceDataLoader
{
    public function __construct(
        private ReferenceYamlLoader $yaml,
        private ReferenceRegistry $registry,
        private EntityManagerInterface $entityManager,
        private HospitalPopulationGeocodingLookupFactory $geocodingLookupFactory,
    ) {
    }

    public function loadAreas(User $user): void
    {
        foreach ($this->yaml->areas() as $row) {
            $stateName = $row['state'];
            try {
                $this->registry->getState($stateName);
            } catch (\RuntimeException) {
                $state = new State()
                    ->setName($stateName)
                    ->setCreatedBy($user);
                $this->entityManager->persist($state);
                $this->registry->registerState($state);
            }

            $area = new DispatchArea()
                ->setName($row['name'])
                ->setState($this->registry->getState($stateName))
                ->setCreatedBy($user);
            $this->entityManager->persist($area);
            $this->registry->registerDispatchArea($area);
        }
    }

    public function loadLookups(User $user): void
    {
        $this->loadNameEntities(Department::class, $this->yaml->names('departments.yaml'), $user);
        $this->loadNameEntities(Speciality::class, $this->yaml->names('specialities.yaml'), $user);
        $this->loadNameEntities(Assignment::class, $this->yaml->names('assignments.yaml'), $user);
        $this->loadNameEntities(Occasion::class, $this->yaml->names('occasions.yaml'), $user);
        $this->loadNameEntities(Infection::class, $this->yaml->names('infections.yaml'), $user);
        $this->loadNameEntities(SecondaryTransport::class, $this->yaml->names('secondary_transports.yaml'), $user);
    }

    public function loadHospitals(User $user, bool $all = true, ?int $limit = null): void
    {
        $rows = $this->yaml->hospitals();
        if (!$all && null !== $limit) {
            $rows = $this->filterHospitalRowsForSubset($rows, $limit);
        }

        $geocoding = $this->geocodingLookupFactory->create();

        foreach ($rows as $row) {
            $dispatchArea = $this->registry->getDispatchArea($row['state'], $row['area']);
            $state = $this->registry->getState($row['state']);

            /** @var array{street: string, city: string, state: string, postalCode: string, country: string} $addressData */
            $addressData = $row['address'];

            $hospital = new Hospital()
                ->setName($row['name'])
                ->setState($state)
                ->setDispatchArea($dispatchArea)
                ->setTier(isset($row['tier']) && \is_string($row['tier']) ? HospitalTier::from($row['tier']) : null)
                ->setLocation(HospitalLocation::from($row['location']))
                ->setSize(HospitalSize::from($row['size']))
                ->setBeds((int) $row['beds'])
                ->setAddress(
                    new Address()
                        ->setStreet($addressData['street'])
                        ->setCity($addressData['city'])
                        ->setPostalCode($addressData['postalCode'])
                        ->setCountry($addressData['country'])
                        ->setState($addressData['state']),
                )
                ->setIsParticipating((bool) $row['participating'])
                ->setOwner(null)
                ->setCreatedBy($user);

            $coordinates = $geocoding->resolve(
                $addressData['postalCode'],
                $addressData['city'],
                $row['area'],
            );
            if ($coordinates instanceof HospitalPopulationCoordinates) {
                $hospital
                    ->setLatitude($coordinates->latitude)
                    ->setLongitude($coordinates->longitude);
            }

            $this->entityManager->persist($hospital);
            $this->registry->registerHospital($hospital);
        }
    }

    public function loadIndications(User $user): void
    {
        foreach ($this->yaml->indicationsNormalized() as $row) {
            $entity = new IndicationNormalized()
                ->setCode((int) $row['code'])
                ->setName($row['name'])
                ->setCreatedBy($user);
            $this->entityManager->persist($entity);
        }

        foreach ($this->yaml->indicationsRaw() as $row) {
            if (!isset($row['code'])) {
                throw new \InvalidArgumentException('Indication raw row missing code.');
            }
            $code = (int) $row['code'];
            $name = (string) ($row['name'] ?? '');
            $hash = IndicationKey::hashFrom((string) $code, $name);

            $raw = new IndicationRaw()
                ->setCode($code)
                ->setName($name)
                ->setHash($hash)
                ->setCreatedBy($user);

            $this->entityManager->persist($raw);
        }

        $this->entityManager->flush();
        $this->linkIndicationRawToNormalized();
        $this->entityManager->flush();
    }

    private function linkIndicationRawToNormalized(): void
    {
        $normalizedList = $this->entityManager
            ->getRepository(IndicationNormalized::class)
            ->findBy([], ['id' => 'ASC']);

        foreach ($normalizedList as $normalized) {
            $hash = IndicationKey::hashFrom((string) $normalized->getCode(), $normalized->getName());
            $raw = $this->entityManager->getRepository(IndicationRaw::class)->findOneBy(['hash' => $hash]);
            if (null === $raw) {
                $createdBy = $normalized->getCreatedBy();
                if (null === $createdBy) {
                    throw new \LogicException(sprintf('Indication normalized "%s" is missing createdBy.', (string) $normalized->getName()));
                }

                $raw = new IndicationRaw();
                $raw->setCode((int) $normalized->getCode());
                $raw->setName((string) $normalized->getName());
                $raw->setHash($hash);
                $raw->setCreatedBy($createdBy);
                $this->entityManager->persist($raw);
            }

            $raw->setTarget($normalized);
            $raw->setNormalized($normalized);
        }
    }

    /**
     * @param class-string $class
     * @param list<string> $names
     */
    private function loadNameEntities(string $class, array $names, User $user): void
    {
        foreach ($names as $name) {
            $entity = new $class();
            if (!method_exists($entity, 'setName') || !method_exists($entity, 'setCreatedBy')) {
                throw new \LogicException(sprintf('Entity %s is not a name-based lookup.', $class));
            }
            $entity->setName($name);
            $entity->setCreatedBy($user);
            $this->entityManager->persist($entity);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function filterHospitalRowsForSubset(array $rows, int $limit): array
    {
        /** @var array<string, list<array<string, mixed>>> $bySize */
        $bySize = [];
        foreach ($rows as $row) {
            $bySize[(string) $row['size']][] = $row;
        }

        $selected = [];
        foreach ([HospitalSize::LARGE->value, HospitalSize::MEDIUM->value, HospitalSize::SMALL->value] as $size) {
            foreach ($bySize[$size] ?? [] as $row) {
                if ((bool) ($row['participating'] ?? false)) {
                    $selected[] = $row;
                    if (\count($selected) >= $limit) {
                        return $selected;
                    }
                }
            }
        }

        foreach ($rows as $row) {
            if (\in_array($row, $selected, true)) {
                continue;
            }
            $selected[] = $row;
            if (\count($selected) >= $limit) {
                break;
            }
        }

        return \array_slice($selected, 0, $limit);
    }
}
