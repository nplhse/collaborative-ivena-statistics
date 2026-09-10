<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog;

use App\Allocation\Domain\Entity\Address;
use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Assignment;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAccessGrant;
use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Domain\Entity\MciCase;
use App\Allocation\Domain\Entity\Occasion;
use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Allocation\Domain\Entity\Speciality;
use App\Allocation\Domain\Entity\State;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Domain\IndicationKey;
use App\Import\Domain\Entity\Import;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ReferenceCatalogSynchronizer
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<ReferenceCatalogType> $types
     */
    public function sync(
        ReferenceCatalogDocument $document,
        User $user,
        array $types,
        bool $replace = false,
        bool $updateIndicationGroups = false,
        bool $dryRun = false,
    ): ReferenceCatalogSyncResult {
        if ($replace && $types !== ReferenceCatalogType::importOrder()) {
            throw new \InvalidArgumentException('Replace mode reloads the full catalog. Omit --types, or use --mode=add for a subset.');
        }

        $document = $document->withTypes($types);

        if ($replace) {
            $this->assertReplaceAllowed();
            if (!$dryRun) {
                $userId = $user->getId();
                $this->purgeCatalogTables();
                $user = null === $userId ? $user : $this->entityManager->find(User::class, $userId);
                if (!$user instanceof User) {
                    throw new \RuntimeException('Created-by user was lost while replacing the catalog.');
                }
            }
        }

        $created = [];
        $skipped = [];
        $updated = [];
        $warnings = [];

        $statesByName = ($replace && $dryRun) ? [] : $this->indexStates();

        foreach ($types as $type) {
            match ($type) {
                ReferenceCatalogType::State => $this->syncStates($document, $user, $dryRun, $statesByName, $created, $skipped),
                ReferenceCatalogType::DispatchArea => $this->syncDispatchAreas($document, $user, $dryRun, $replace, $statesByName, $created, $skipped, $warnings),
                ReferenceCatalogType::Department => $this->syncNameEntities(Department::class, $document->departments, $type, $user, $dryRun, $replace, $created, $skipped),
                ReferenceCatalogType::Speciality => $this->syncNameEntities(Speciality::class, $document->specialities, $type, $user, $dryRun, $replace, $created, $skipped),
                ReferenceCatalogType::Assignment => $this->syncNameEntities(Assignment::class, $document->assignments, $type, $user, $dryRun, $replace, $created, $skipped),
                ReferenceCatalogType::Occasion => $this->syncNameEntities(Occasion::class, $document->occasions, $type, $user, $dryRun, $replace, $created, $skipped),
                ReferenceCatalogType::Infection => $this->syncNameEntities(Infection::class, $document->infections, $type, $user, $dryRun, $replace, $created, $skipped),
                ReferenceCatalogType::SecondaryTransport => $this->syncNameEntities(SecondaryTransport::class, $document->secondaryTransports, $type, $user, $dryRun, $replace, $created, $skipped),
                ReferenceCatalogType::IndicationNormalized => $this->syncIndicationsNormalized($document, $user, $dryRun, $replace, $created, $skipped),
                ReferenceCatalogType::IndicationRaw => $this->syncIndicationsRaw($document, $user, $dryRun, $replace, $created, $skipped),
                ReferenceCatalogType::IndicationGroup => $this->syncIndicationGroups($document, $user, $dryRun, $replace, $updateIndicationGroups, $created, $skipped, $updated, $warnings),
                ReferenceCatalogType::Hospital => $this->syncHospitals($document, $user, $dryRun, $replace, $statesByName, $created, $skipped, $warnings),
            };

            if (!$dryRun) {
                $this->entityManager->flush();
            }
        }

        return new ReferenceCatalogSyncResult($created, $skipped, $updated, $warnings);
    }

    private function assertReplaceAllowed(): void
    {
        $allocationCount = (int) $this->entityManager
            ->createQuery('SELECT COUNT(a.id) FROM '.Allocation::class.' a')
            ->getSingleScalarResult();
        $mciCount = (int) $this->entityManager
            ->createQuery('SELECT COUNT(c.id) FROM '.MciCase::class.' c')
            ->getSingleScalarResult();
        $importCount = $this->entityManager->getRepository(Import::class)->count([]);

        if ($allocationCount > 0 || $mciCount > 0 || $importCount > 0) {
            throw new ReferenceCatalogReplaceBlockedException(sprintf('Replace aborted: database contains %d allocation(s), %d MCI case(s), and %d import(s). Use --mode=add, or replace only on an empty installation after migrate.', $allocationCount, $mciCount, $importCount));
        }
    }

    private function purgeCatalogTables(): void
    {
        $this->entityManager->getConnection()->executeStatement('DELETE FROM indication_group_indication_normalized');
        $this->entityManager->createQuery('DELETE FROM '.IndicationGroup::class)->execute();
        $this->entityManager->createQuery('UPDATE '.IndicationRaw::class.' r SET r.normalized = NULL, r.target = NULL')->execute();
        $this->entityManager->createQuery('DELETE FROM '.IndicationRaw::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.IndicationNormalized::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.HospitalAccessGrant::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.Hospital::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.DispatchArea::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.Department::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.Speciality::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.Assignment::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.Occasion::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.Infection::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.SecondaryTransport::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.State::class)->execute();
        $this->entityManager->clear();
    }

    /**
     * @return array<string, State>
     */
    private function indexStates(): array
    {
        $states = [];
        foreach ($this->entityManager->getRepository(State::class)->findBy([]) as $state) {
            $name = (string) $state->getName();
            $states[$name] = $state;
        }

        return $states;
    }

    /**
     * @param array<string, State> $statesByName
     * @param array<string, int>   $created
     * @param array<string, int>   $skipped
     */
    private function syncStates(
        ReferenceCatalogDocument $document,
        User $user,
        bool $dryRun,
        array &$statesByName,
        array &$created,
        array &$skipped,
    ): void {
        $type = ReferenceCatalogType::State->value;
        foreach ($document->states as $name) {
            if (isset($statesByName[$name])) {
                $this->bump($skipped, $type);
                continue;
            }

            $this->bump($created, $type);
            if ($dryRun) {
                continue;
            }

            $state = new State()
                ->setName($name)
                ->setCreatedBy($user);
            $this->entityManager->persist($state);
            $statesByName[$name] = $state;
        }
    }

    /**
     * @param array<string, State> $statesByName
     * @param array<string, int>   $created
     * @param array<string, int>   $skipped
     * @param list<string>         $warnings
     */
    private function syncDispatchAreas(
        ReferenceCatalogDocument $document,
        User $user,
        bool $dryRun,
        bool $replace,
        array &$statesByName,
        array &$created,
        array &$skipped,
        array &$warnings,
    ): void {
        $type = ReferenceCatalogType::DispatchArea->value;
        $existing = $replace && $dryRun ? [] : $this->existingDispatchAreaKeys();

        foreach ($document->dispatchAreas as $row) {
            $stateName = $row['state'];
            if (null === $stateName || '' === $stateName) {
                $warnings[] = sprintf('Dispatch area "%s" skipped: state is empty.', $row['name']);
                $this->bump($skipped, $type);
                continue;
            }

            $key = $stateName.'|'.$row['name'];
            if (isset($existing[$key])) {
                $this->bump($skipped, $type);
                continue;
            }

            $state = $statesByName[$stateName] ?? null;
            if (!$state instanceof State) {
                $warnings[] = sprintf(
                    'Dispatch area "%s" skipped: state "%s" does not exist.',
                    $row['name'],
                    $stateName,
                );
                $this->bump($skipped, $type);
                continue;
            }

            $this->bump($created, $type);
            $existing[$key] = true;
            if ($dryRun) {
                continue;
            }

            $area = new DispatchArea()
                ->setName($row['name'])
                ->setState($state)
                ->setCreatedBy($user);
            $this->entityManager->persist($area);
        }
    }

    /**
     * @return array<string, true>
     */
    private function existingDispatchAreaKeys(): array
    {
        $keys = [];
        foreach ($this->entityManager->getRepository(DispatchArea::class)->findBy([]) as $area) {
            $stateName = $area->getState()?->getName();
            $name = $area->getName();
            if (null === $stateName || null === $name) {
                continue;
            }

            $keys[$stateName.'|'.$name] = true;
        }

        return $keys;
    }

    /**
     * @param class-string       $class
     * @param list<string>       $names
     * @param array<string, int> $created
     * @param array<string, int> $skipped
     */
    private function syncNameEntities(
        string $class,
        array $names,
        ReferenceCatalogType $type,
        User $user,
        bool $dryRun,
        bool $replace,
        array &$created,
        array &$skipped,
    ): void {
        $existing = [];
        if (!$replace || !$dryRun) {
            foreach ($this->entityManager->getRepository($class)->findBy([]) as $entity) {
                if (!method_exists($entity, 'getName')) {
                    throw new \LogicException(sprintf('Entity %s is not a name-based lookup.', $class));
                }

                $existing[(string) $entity->getName()] = true;
            }
        }

        foreach ($names as $name) {
            if (isset($existing[$name])) {
                $this->bump($skipped, $type->value);
                continue;
            }

            $this->bump($created, $type->value);
            $existing[$name] = true;
            if ($dryRun) {
                continue;
            }

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
     * @param array<string, int> $created
     * @param array<string, int> $skipped
     */
    private function syncIndicationsNormalized(
        ReferenceCatalogDocument $document,
        User $user,
        bool $dryRun,
        bool $replace,
        array &$created,
        array &$skipped,
    ): void {
        $type = ReferenceCatalogType::IndicationNormalized->value;
        $existing = [];
        if (!$replace || !$dryRun) {
            foreach ($this->entityManager->getRepository(IndicationNormalized::class)->findBy([]) as $entity) {
                $existing[$this->indicationPairKey((int) $entity->getCode(), (string) $entity->getName())] = true;
            }
        }

        foreach ($document->indicationsNormalized as $row) {
            $code = (int) IndicationKey::normalizeCode($row['code']);
            $key = $this->indicationPairKey($code, $row['name']);
            if (isset($existing[$key])) {
                $this->bump($skipped, $type);
                continue;
            }

            $this->bump($created, $type);
            $existing[$key] = true;
            if ($dryRun) {
                continue;
            }

            $entity = new IndicationNormalized()
                ->setCode($code)
                ->setName($row['name'])
                ->setCreatedBy($user);
            $this->entityManager->persist($entity);
        }
    }

    /**
     * @param array<string, int> $created
     * @param array<string, int> $skipped
     */
    private function syncIndicationsRaw(
        ReferenceCatalogDocument $document,
        User $user,
        bool $dryRun,
        bool $replace,
        array &$created,
        array &$skipped,
    ): void {
        $type = ReferenceCatalogType::IndicationRaw->value;
        $existingHashes = [];
        if (!$replace || !$dryRun) {
            foreach ($this->entityManager->getRepository(IndicationRaw::class)->findBy([]) as $entity) {
                $existingHashes[(string) $entity->getHash()] = true;
            }
        }

        $normalizedByHash = [];
        if (!$dryRun) {
            foreach ($this->entityManager->getRepository(IndicationNormalized::class)->findBy([]) as $normalized) {
                $hash = IndicationKey::hashFrom((string) $normalized->getCode(), (string) $normalized->getName());
                $normalizedByHash[$hash] = $normalized;
            }
        }

        foreach ($document->indicationsRaw as $row) {
            $code = (int) IndicationKey::normalizeCode($row['code']);
            $hash = IndicationKey::hashFrom((string) $code, $row['name']);
            if (isset($existingHashes[$hash])) {
                $this->bump($skipped, $type);
                continue;
            }

            $this->bump($created, $type);
            $existingHashes[$hash] = true;
            if ($dryRun) {
                continue;
            }

            $raw = new IndicationRaw()
                ->setCode($code)
                ->setName($row['name'])
                ->setHash($hash)
                ->setCreatedBy($user);

            $normalized = $normalizedByHash[$hash] ?? null;
            if ($normalized instanceof IndicationNormalized) {
                $raw->setTarget($normalized);
                $raw->setNormalized($normalized);
            }

            $this->entityManager->persist($raw);
        }
    }

    /**
     * @param array<string, int> $created
     * @param array<string, int> $skipped
     * @param array<string, int> $updated
     * @param list<string>       $warnings
     */
    private function syncIndicationGroups(
        ReferenceCatalogDocument $document,
        User $user,
        bool $dryRun,
        bool $replace,
        bool $updateExisting,
        array &$created,
        array &$skipped,
        array &$updated,
        array &$warnings,
    ): void {
        $type = ReferenceCatalogType::IndicationGroup->value;
        $repository = $this->entityManager->getRepository(IndicationGroup::class);

        foreach ($document->indicationGroups as $definition) {
            $existing = ($replace && $dryRun) ? null : $repository->findOneBy(['name' => $definition['name']]);
            if ($existing instanceof IndicationGroup && !$updateExisting) {
                $this->bump($skipped, $type);
                continue;
            }

            if ($existing instanceof IndicationGroup) {
                $this->bump($updated, $type);
            } else {
                $this->bump($created, $type);
            }

            if ($dryRun) {
                continue;
            }

            $group = $existing ?? new IndicationGroup()
                ->setName($definition['name'])
                ->setCreatedBy($user);
            $group->setCategory($definition['category'] ?? null);

            $desired = $this->resolveGroupIndications($definition['name'], $definition['codes'], $warnings);
            foreach ($group->getIndications()->toArray() as $indication) {
                $indicationId = $indication->getId();
                if (null === $indicationId || !isset($desired[$indicationId])) {
                    $group->removeIndication($indication);
                }
            }
            foreach ($desired as $indication) {
                $group->addIndication($indication);
            }

            $this->entityManager->persist($group);
        }
    }

    /**
     * @param list<string> $codes
     * @param list<string> $warnings
     *
     * @return array<int, IndicationNormalized>
     */
    private function resolveGroupIndications(string $groupName, array $codes, array &$warnings): array
    {
        $repository = $this->entityManager->getRepository(IndicationNormalized::class);
        $desired = [];

        foreach ($codes as $code) {
            $indications = $repository->findBy(['code' => (int) IndicationKey::normalizeCode($code)]);
            if ([] === $indications) {
                $warnings[] = sprintf('Group "%s": no normalized indication for code %s', $groupName, $code);
                continue;
            }

            foreach ($indications as $indication) {
                $id = $indication->getId();
                if (null !== $id) {
                    $desired[$id] = $indication;
                }
            }
        }

        return $desired;
    }

    /**
     * @param array<string, State> $statesByName
     * @param array<string, int>   $created
     * @param array<string, int>   $skipped
     * @param list<string>         $warnings
     */
    private function syncHospitals(
        ReferenceCatalogDocument $document,
        User $user,
        bool $dryRun,
        bool $replace,
        array $statesByName,
        array &$created,
        array &$skipped,
        array &$warnings,
    ): void {
        $type = ReferenceCatalogType::Hospital->value;
        $existingNames = [];
        if (!$replace || !$dryRun) {
            foreach ($this->entityManager->getRepository(Hospital::class)->findBy([]) as $hospital) {
                $existingNames[(string) $hospital->getName()] = true;
            }
        }

        $areasByKey = [];
        foreach ($this->entityManager->getRepository(DispatchArea::class)->findBy([]) as $area) {
            $stateName = $area->getState()?->getName();
            $name = $area->getName();
            if (null === $stateName || null === $name) {
                continue;
            }

            $areasByKey[$stateName.'|'.$name] = $area;
        }

        foreach ($document->hospitals as $row) {
            if (isset($existingNames[$row['name']])) {
                $this->bump($skipped, $type);
                continue;
            }

            $state = $statesByName[$row['state']] ?? null;
            $area = $areasByKey[$row['state'].'|'.$row['area']] ?? null;
            if (!$state instanceof State || !$area instanceof DispatchArea) {
                $warnings[] = sprintf(
                    'Hospital "%s" skipped: state "%s" / area "%s" not found.',
                    $row['name'],
                    $row['state'],
                    $row['area'],
                );
                $this->bump($skipped, $type);
                continue;
            }

            $this->bump($created, $type);
            $existingNames[$row['name']] = true;
            if ($dryRun) {
                continue;
            }

            $hospital = new Hospital()
                ->setName($row['name'])
                ->setState($state)
                ->setDispatchArea($area)
                ->setTier(null !== $row['tier'] && '' !== $row['tier'] ? HospitalTier::from($row['tier']) : null)
                ->setLocation(HospitalLocation::from($row['location']))
                ->setSize(HospitalSize::from($row['size']))
                ->setBeds($row['beds'])
                ->setAddress(
                    new Address()
                        ->setStreet($row['address']['street'])
                        ->setCity($row['address']['city'])
                        ->setPostalCode($row['address']['postalCode'])
                        ->setCountry($row['address']['country'])
                        ->setState($row['address']['state']),
                )
                ->setIsParticipating($row['participating'])
                ->setOwner(null)
                ->setCreatedBy($user);

            if ($row['participating']) {
                $hospital->setParticipatingSince($hospital->getCreatedAt());
            }

            $this->entityManager->persist($hospital);
            $areasByKey[$row['state'].'|'.$row['area']] = $area;
        }
    }

    private function indicationPairKey(int $code, string $name): string
    {
        return $code.'|'.$name;
    }

    /**
     * @param array<string, int> $counts
     */
    private function bump(array &$counts, string $type): void
    {
        $counts[$type] = ($counts[$type] ?? 0) + 1;
    }
}
