<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog;

use App\Allocation\Domain\Entity\Assignment;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Domain\Entity\Occasion;
use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Allocation\Domain\Entity\Speciality;
use App\Allocation\Domain\Entity\State;
use App\Allocation\Domain\IndicationKey;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ReferenceCatalogExporter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<ReferenceCatalogType> $types
     */
    public function export(array $types): ReferenceCatalogDocument
    {
        $document = new ReferenceCatalogDocument();

        foreach ($types as $type) {
            match ($type) {
                ReferenceCatalogType::State => $document->states = $this->nameList(State::class),
                ReferenceCatalogType::DispatchArea => $document->dispatchAreas = $this->dispatchAreas(),
                ReferenceCatalogType::Department => $document->departments = $this->nameList(Department::class),
                ReferenceCatalogType::Speciality => $document->specialities = $this->nameList(Speciality::class),
                ReferenceCatalogType::Assignment => $document->assignments = $this->nameList(Assignment::class),
                ReferenceCatalogType::Occasion => $document->occasions = $this->nameList(Occasion::class),
                ReferenceCatalogType::Infection => $document->infections = $this->nameList(Infection::class),
                ReferenceCatalogType::SecondaryTransport => $document->secondaryTransports = $this->nameList(SecondaryTransport::class),
                ReferenceCatalogType::IndicationNormalized => $document->indicationsNormalized = $this->indications(IndicationNormalized::class),
                ReferenceCatalogType::IndicationRaw => $document->indicationsRaw = $this->indications(IndicationRaw::class),
                ReferenceCatalogType::IndicationGroup => $document->indicationGroups = $this->indicationGroups(),
                ReferenceCatalogType::Hospital => $document->hospitals = $this->hospitals(),
            };
        }

        return $document;
    }

    /**
     * @param class-string $class
     *
     * @return list<string>
     */
    private function nameList(string $class): array
    {
        $names = [];
        foreach ($this->entityManager->getRepository($class)->findBy([], ['name' => 'ASC']) as $entity) {
            if (!method_exists($entity, 'getName')) {
                throw new \LogicException(sprintf('Entity %s is not a name-based lookup.', $class));
            }

            $names[] = (string) $entity->getName();
        }

        return $names;
    }

    /**
     * @return list<array{name: string, state: ?string}>
     */
    private function dispatchAreas(): array
    {
        $rows = [];
        foreach ($this->entityManager->getRepository(DispatchArea::class)->findBy([], ['name' => 'ASC']) as $area) {
            $rows[] = [
                'name' => (string) $area->getName(),
                'state' => $area->getState()?->getName(),
            ];
        }

        usort(
            $rows,
            static fn (array $left, array $right): int => [$left['state'] ?? '', $left['name']] <=> [$right['state'] ?? '', $right['name']],
        );

        return $rows;
    }

    /**
     * @param class-string<IndicationNormalized|IndicationRaw> $class
     *
     * @return list<array{code: string, name: string}>
     */
    private function indications(string $class): array
    {
        $rows = [];
        foreach ($this->entityManager->getRepository($class)->findBy([], ['code' => 'ASC', 'name' => 'ASC']) as $entity) {
            $rows[] = [
                'code' => IndicationKey::normalizeCode((string) $entity->getCode()),
                'name' => (string) $entity->getName(),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{name: string, category: ?string, codes: list<string>}>
     */
    private function indicationGroups(): array
    {
        $rows = [];
        foreach ($this->entityManager->getRepository(IndicationGroup::class)->findBy([], ['name' => 'ASC']) as $group) {
            $codes = [];
            foreach ($group->getIndications() as $indication) {
                $codes[] = IndicationKey::normalizeCode((string) $indication->getCode());
            }
            $codes = array_values(array_unique($codes));
            sort($codes);

            $rows[] = [
                'name' => (string) $group->getName(),
                'category' => $group->getCategory(),
                'codes' => $codes,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{
     *     name: string,
     *     state: string,
     *     area: string,
     *     participating: bool,
     *     tier: ?string,
     *     size: string,
     *     beds: int,
     *     location: string,
     *     address: array{street: string, city: string, state: string, postalCode: string, country: string}
     * }>
     */
    private function hospitals(): array
    {
        $rows = [];
        foreach ($this->entityManager->getRepository(Hospital::class)->findBy([], ['name' => 'ASC']) as $hospital) {
            $address = $hospital->getAddress();
            $rows[] = [
                'name' => (string) $hospital->getName(),
                'state' => (string) $hospital->getState()?->getName(),
                'area' => (string) $hospital->getDispatchArea()?->getName(),
                'participating' => $hospital->isParticipating(),
                'tier' => $hospital->getTier()?->value,
                'size' => (string) $hospital->getSize()?->value,
                'beds' => (int) $hospital->getBeds(),
                'location' => (string) $hospital->getLocation()?->value,
                'address' => [
                    'street' => $address->getStreet(),
                    'city' => $address->getCity(),
                    'state' => $address->getState(),
                    'postalCode' => $address->getPostalCode(),
                    'country' => $address->getCountry(),
                ],
            ];
        }

        return $rows;
    }
}
