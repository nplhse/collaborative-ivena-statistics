<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog;

use App\Allocation\Application\ReferenceCatalog\Package\AliasedEntry;
use App\Allocation\Application\ReferenceCatalog\Package\AliasEntry;
use App\Allocation\Application\ReferenceCatalog\Package\CatalogEntry;
use App\Allocation\Application\ReferenceCatalog\Package\DispatchAreaEntry;
use App\Allocation\Application\ReferenceCatalog\Package\HospitalAddress;
use App\Allocation\Application\ReferenceCatalog\Package\HospitalCoordinates;
use App\Allocation\Application\ReferenceCatalog\Package\HospitalEntry;
use App\Allocation\Application\ReferenceCatalog\Package\IndicationGroupEntry;
use App\Allocation\Application\ReferenceCatalog\Package\NamedEntry;
use App\Allocation\Application\ReferenceCatalog\Package\NormalizedIndicationEntry;
use App\Allocation\Application\ReferenceCatalog\Package\PackageChecksum;
use App\Allocation\Application\ReferenceCatalog\Package\PackageMeta;
use App\Allocation\Application\ReferenceCatalog\Package\RawIndicationEntry;
use App\Allocation\Application\ReferenceCatalog\Package\RawIndicationReview;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogFormat;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackage;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackageWriter;
use App\Allocation\Application\ReferenceCatalog\Package\StateEntry;
use App\Allocation\Domain\Entity\Assignment;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\DepartmentAlias;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Domain\Entity\Occasion;
use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Allocation\Domain\Entity\Speciality;
use App\Allocation\Domain\Entity\SpecialityAlias;
use App\Allocation\Domain\Entity\State;
use App\Allocation\Domain\IndicationKey;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds a portable reference catalog package from the database (ADR 020).
 *
 * Rows are identified by their public id and refer to each other by id. Entries are
 * sorted by natural key, then id, so the same database state gives the same package.
 */
final readonly class ReferenceCatalogExporter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ReferenceCatalogPackageWriter $writer,
        private ClockInterface $clock,
        #[Autowire(param: 'app.version')]
        private string $appVersion,
        #[Autowire(param: 'kernel.environment')]
        private string $environment,
    ) {
    }

    /**
     * @param list<ReferenceCatalogType> $types
     */
    public function export(array $types, ?string $notes = null): ReferenceCatalogPackage
    {
        $listed = [];
        foreach (ReferenceCatalogType::importOrder() as $type) {
            if (\in_array($type, $types, true)) {
                $listed[] = $type;
            }
        }

        $has = static fn (ReferenceCatalogType $type): bool => \in_array($type, $listed, true);

        return $this->writer->seal(new ReferenceCatalogPackage(
            new PackageMeta(
                ReferenceCatalogFormat::NAME,
                ReferenceCatalogFormat::VERSION,
                $this->clock->now()->format(\DateTimeInterface::ATOM),
                \count($listed) === \count(ReferenceCatalogType::cases()),
                array_map(static fn (ReferenceCatalogType $type): string => $type->value, $listed),
                [],
                new PackageChecksum(ReferenceCatalogFormat::CHECKSUM_ALGORITHM, ''),
                $this->appVersion,
                $this->environment,
                [],
                $notes,
            ),
            $has(ReferenceCatalogType::State) ? $this->states() : [],
            $has(ReferenceCatalogType::DispatchArea) ? $this->dispatchAreas() : [],
            $has(ReferenceCatalogType::Department) ? $this->aliasedEntries(Department::class, DepartmentAlias::class) : [],
            $has(ReferenceCatalogType::Speciality) ? $this->aliasedEntries(Speciality::class, SpecialityAlias::class) : [],
            $has(ReferenceCatalogType::Assignment) ? $this->namedEntries(Assignment::class) : [],
            $has(ReferenceCatalogType::Occasion) ? $this->namedEntries(Occasion::class) : [],
            $has(ReferenceCatalogType::Infection) ? $this->namedEntries(Infection::class) : [],
            $has(ReferenceCatalogType::SecondaryTransport) ? $this->namedEntries(SecondaryTransport::class) : [],
            $has(ReferenceCatalogType::IndicationNormalized) ? $this->normalizedIndications() : [],
            $has(ReferenceCatalogType::IndicationRaw) ? $this->rawIndications() : [],
            $has(ReferenceCatalogType::IndicationGroup) ? $this->indicationGroups() : [],
            $has(ReferenceCatalogType::Hospital) ? $this->hospitals() : [],
        ));
    }

    /**
     * @return list<StateEntry>
     */
    private function states(): array
    {
        $entries = [];
        foreach ($this->entityManager->getRepository(State::class)->findAll() as $state) {
            $entries[] = new StateEntry($state->getPublicIdString(), (string) $state->getName());
        }

        return $this->sorted($entries, static fn (StateEntry $entry): string => ReferenceNameKey::normalize($entry->name));
    }

    /**
     * @return list<DispatchAreaEntry>
     */
    private function dispatchAreas(): array
    {
        $entries = [];
        $stateNames = [];
        foreach ($this->entityManager->getRepository(DispatchArea::class)->findAll() as $area) {
            $state = $area->getState();
            $entry = new DispatchAreaEntry($area->getPublicIdString(), (string) $area->getName(), (string) $state?->getPublicIdString());
            $entries[] = $entry;
            $stateNames[$entry->id] = ReferenceNameKey::normalize((string) $state?->getName());
        }

        return $this->sorted($entries, static fn (DispatchAreaEntry $entry): string => $stateNames[$entry->id].'|'.ReferenceNameKey::normalize($entry->name));
    }

    /**
     * @param class-string<Department|Speciality>           $class
     * @param class-string<DepartmentAlias|SpecialityAlias> $aliasClass
     *
     * @return list<AliasedEntry>
     */
    private function aliasedEntries(string $class, string $aliasClass): array
    {
        /** @var array<string, list<AliasEntry>> $aliasesByOwner */
        $aliasesByOwner = [];
        foreach ($this->entityManager->getRepository($aliasClass)->findAll() as $alias) {
            $owner = $alias instanceof DepartmentAlias ? $alias->getDepartment() : $alias->getSpeciality();
            $aliasesByOwner[$owner->getPublicIdString()][] = new AliasEntry(
                $alias->getName(),
                $alias->getClassification(),
                $alias->getSource(),
                $alias->getNote(),
                $alias->getValidFrom()?->format('Y-m-d'),
                $alias->getValidTo()?->format('Y-m-d'),
            );
        }

        $entries = [];
        foreach ($this->entityManager->getRepository($class)->findAll() as $entity) {
            $id = $entity->getPublicIdString();
            $aliases = $aliasesByOwner[$id] ?? [];
            usort($aliases, static fn (AliasEntry $left, AliasEntry $right): int => ReferenceNameKey::normalize($left->name) <=> ReferenceNameKey::normalize($right->name));

            $entries[] = new AliasedEntry($id, (string) $entity->getName(), $aliases);
        }

        return $this->sorted($entries, static fn (AliasedEntry $entry): string => ReferenceNameKey::normalize($entry->name));
    }

    /**
     * @param class-string<Assignment|Occasion|Infection|SecondaryTransport> $class
     *
     * @return list<NamedEntry>
     */
    private function namedEntries(string $class): array
    {
        $entries = [];
        foreach ($this->entityManager->getRepository($class)->findAll() as $entity) {
            $entries[] = new NamedEntry($entity->getPublicIdString(), (string) $entity->getName());
        }

        return $this->sorted($entries, static fn (NamedEntry $entry): string => ReferenceNameKey::normalize($entry->name));
    }

    /**
     * @return list<NormalizedIndicationEntry>
     */
    private function normalizedIndications(): array
    {
        $entries = [];
        foreach ($this->entityManager->getRepository(IndicationNormalized::class)->findAll() as $indication) {
            $entries[] = new NormalizedIndicationEntry(
                $indication->getPublicIdString(),
                IndicationKey::normalizeCode((string) $indication->getCode()),
                (string) $indication->getName(),
                $indication->getNote(),
            );
        }

        return $this->sorted($entries, static fn (NormalizedIndicationEntry $entry): string => $entry->code.'|'.ReferenceNameKey::normalize($entry->name));
    }

    /**
     * Only the curated target is exported; the import sets the normalized link to it (ADR 020).
     *
     * @return list<RawIndicationEntry>
     */
    private function rawIndications(): array
    {
        $entries = [];
        foreach ($this->entityManager->getRepository(IndicationRaw::class)->findAll() as $indication) {
            $entries[] = new RawIndicationEntry(
                $indication->getPublicIdString(),
                IndicationKey::normalizeCode((string) $indication->getCode()),
                (string) $indication->getName(),
                new RawIndicationReview(
                    $indication->getReviewStatus()->value,
                    $indication->getReviewComment(),
                    $indication->getReviewedAt()?->format(\DateTimeInterface::ATOM),
                ),
                $indication->getTarget()?->getPublicIdString(),
                $indication->getFirstMatchedAt()?->format(\DateTimeInterface::ATOM),
            );
        }

        return $this->sorted($entries, static fn (RawIndicationEntry $entry): string => $entry->code.'|'.ReferenceNameKey::normalize($entry->name));
    }

    /**
     * Members are the exact normalized rows of the group, not every row that shares a code.
     *
     * @return list<IndicationGroupEntry>
     */
    private function indicationGroups(): array
    {
        $entries = [];
        foreach ($this->entityManager->getRepository(IndicationGroup::class)->findAll() as $group) {
            $members = [];
            foreach ($group->getIndications() as $indication) {
                $members[] = $indication->getPublicIdString();
            }
            sort($members);

            $entries[] = new IndicationGroupEntry(
                $group->getPublicIdString(),
                (string) $group->getName(),
                $members,
                $group->getCategory(),
                $group->getDescription(),
                $group->getSortOrder(),
            );
        }

        return $this->sorted($entries, static fn (IndicationGroupEntry $entry): string => ReferenceNameKey::normalize($entry->name));
    }

    /**
     * Owner and participation stay in the database; they are not reference data (ADR 020).
     *
     * @return list<HospitalEntry>
     */
    private function hospitals(): array
    {
        $entries = [];
        foreach ($this->entityManager->getRepository(Hospital::class)->findAll() as $hospital) {
            $address = $hospital->getAddress();
            $latitude = $hospital->getLatitude();
            $longitude = $hospital->getLongitude();

            $entries[] = new HospitalEntry(
                $hospital->getPublicIdString(),
                (string) $hospital->getName(),
                (string) $hospital->getState()?->getPublicIdString(),
                (string) $hospital->getDispatchArea()?->getPublicIdString(),
                (string) $hospital->getSize()?->value,
                (int) $hospital->getBeds(),
                (string) $hospital->getLocation()?->value,
                new HospitalAddress(
                    $address->getStreet(),
                    $address->getPostalCode(),
                    $address->getCity(),
                    $address->getState(),
                    $address->getCountry(),
                ),
                $hospital->getTier()?->value,
                null !== $latitude && null !== $longitude ? new HospitalCoordinates($latitude, $longitude) : null,
            );
        }

        return $this->sorted($entries, static fn (HospitalEntry $entry): string => ReferenceNameKey::normalize($entry->name));
    }

    /**
     * Sorts in PHP, so the order does not depend on the database collation.
     *
     * @template T of CatalogEntry
     *
     * @param list<T>             $entries
     * @param callable(T): string $key
     *
     * @return list<T>
     */
    private function sorted(array $entries, callable $key): array
    {
        $keyed = [];
        foreach ($entries as $entry) {
            $keyed[] = ['sort' => [$key($entry), $entry->name, $entry->id], 'entry' => $entry];
        }
        usort($keyed, static fn (array $left, array $right): int => $left['sort'] <=> $right['sort']);

        return array_column($keyed, 'entry');
    }
}
