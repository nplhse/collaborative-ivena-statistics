<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Import;

use App\Allocation\Application\ReferenceCatalog\Package\AliasedEntry;
use App\Allocation\Application\ReferenceCatalog\Package\CatalogEntry;
use App\Allocation\Application\ReferenceCatalog\Package\DispatchAreaEntry;
use App\Allocation\Application\ReferenceCatalog\Package\HospitalEntry;
use App\Allocation\Application\ReferenceCatalog\Package\IndicationGroupEntry;
use App\Allocation\Application\ReferenceCatalog\Package\RawIndicationEntry;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogNaturalKey;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackage;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogViolation;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType;
use App\Allocation\Domain\Entity\DepartmentAlias;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\SpecialityAlias;
use App\Allocation\Domain\Entity\State;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Plans an additive import of a validated package (ADR 020).
 *
 * A row is matched by id, then by natural key. Same id with another natural key, or same
 * natural key with another id, is a conflict. Matched rows are skipped and never changed.
 * Rows to create must only refer to rows in the package or in the database.
 */
final readonly class ReferenceCatalogImportPlanner
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function plan(ReferenceCatalogPackage $package): ReferenceCatalogImportPlan
    {
        $plan = new ImportPlanBuilder();
        $listed = array_values(array_filter(
            ReferenceCatalogType::importOrder(),
            static fn (ReferenceCatalogType $type): bool => \in_array($type->value, $package->meta->types, true),
        ));

        $database = [];
        foreach (ReferenceCatalogType::importOrder() as $type) {
            $database[$type->value] = $this->databaseRows($type);
        }

        $known = [];
        $areaStates = [];
        foreach ($database as $type => $rows) {
            foreach ($rows as $id => $row) {
                $known[$type][$id] = true;
                if (isset($row['state'])) {
                    $areaStates[$id] = $row['state'];
                }
            }
        }
        foreach ($listed as $type) {
            foreach ($package->entriesOf($type) as $entry) {
                $known[$type->value][strtolower($entry->id)] = true;
                if ($entry instanceof DispatchAreaEntry) {
                    $areaStates[strtolower($entry->id)] ??= strtolower($entry->state);
                }
            }
        }

        foreach ($listed as $type) {
            $plan->startType($type);
            $rows = $database[$type->value] ?? [];
            $idsByKey = [];
            foreach ($rows as $id => $row) {
                $idsByKey[$row['key']] ??= $id;
            }
            $aliasOwners = ReferenceCatalogType::Department === $type || ReferenceCatalogType::Speciality === $type
                ? $this->aliasOwners($type)
                : [];

            foreach ($package->entriesOf($type) as $index => $entry) {
                $path = sprintf('%s[%d]', $type->sectionKey(), $index);
                $id = strtolower($entry->id);
                $key = ReferenceCatalogNaturalKey::of($entry);
                $label = ReferenceCatalogNaturalKey::label($type);

                if (isset($rows[$id])) {
                    if ($rows[$id]['key'] !== $key) {
                        $plan->conflict($path.'.id', sprintf('The database has this id for another %s (other %s).', $type->value, $label));

                        continue;
                    }

                    $plan->skip($type);
                } elseif (isset($idsByKey[$key])) {
                    $plan->conflict($path, sprintf('The database has the same %s (%s) with another id: %s.', $type->value, $label, $idsByKey[$key]));

                    continue;
                } else {
                    $problems = $this->unresolvedReferences($entry, $path, $known, $areaStates);
                    if ([] !== $problems) {
                        foreach ($problems as $problem) {
                            $plan->conflict($problem->path, $problem->message);
                        }

                        continue;
                    }

                    if (isset($aliasOwners[$key]) && $aliasOwners[$key] !== $id) {
                        $plan->conflict($path.'.name', sprintf('The name is an alias of another %s in the database (%s).', $type->value, $aliasOwners[$key]));

                        continue;
                    }

                    $plan->create($type, $entry);
                }

                if ($entry instanceof AliasedEntry) {
                    $this->planAliases($plan, $type, $entry, $path, $aliasOwners, $idsByKey);
                }
            }
        }

        return $plan->build();
    }

    /**
     * @param array<string, string> $aliasOwners alias key → owner id, extended with the aliases planned here
     * @param array<string, string> $idsByKey    canonical name key → id in the database
     */
    private function planAliases(ImportPlanBuilder $plan, ReferenceCatalogType $type, AliasedEntry $entry, string $path, array &$aliasOwners, array $idsByKey): void
    {
        $ownerId = strtolower($entry->id);
        foreach ($entry->aliases as $index => $alias) {
            $aliasPath = sprintf('%s.aliases[%d].name', $path, $index);
            $key = ReferenceCatalogNaturalKey::name($alias->name);

            if (isset($aliasOwners[$key])) {
                if ($aliasOwners[$key] === $ownerId) {
                    $plan->skipAlias($type);
                } else {
                    $plan->conflict($aliasPath, sprintf('The alias belongs to another %s in the database (%s).', $type->value, $aliasOwners[$key]));
                }

                continue;
            }

            if (isset($idsByKey[$key]) && $idsByKey[$key] !== $ownerId) {
                $plan->conflict($aliasPath, sprintf('The alias is the name of another %s in the database (%s).', $type->value, $idsByKey[$key]));

                continue;
            }

            $plan->createAlias($type, $ownerId, $alias);
            $aliasOwners[$key] = $ownerId;
        }
    }

    /**
     * @param array<string, array<string, true>> $known      ids per type in the database or the package
     * @param array<string, string>              $areaStates dispatch area id → state id
     *
     * @return list<ReferenceCatalogViolation>
     */
    private function unresolvedReferences(CatalogEntry $entry, string $path, array $known, array $areaStates): array
    {
        $missing = (static fn (ReferenceCatalogType $type, string $id, string $at): ?ReferenceCatalogViolation => isset($known[$type->value][strtolower($id)])
            ? null
            : new ReferenceCatalogViolation($at, sprintf('The %s "%s" is neither in the package nor in the database.', $type->value, $id)));

        $problems = [];
        if ($entry instanceof DispatchAreaEntry) {
            $problems[] = $missing(ReferenceCatalogType::State, $entry->state, $path.'.state');
        } elseif ($entry instanceof RawIndicationEntry && null !== $entry->target) {
            $problems[] = $missing(ReferenceCatalogType::IndicationNormalized, $entry->target, $path.'.target');
        } elseif ($entry instanceof IndicationGroupEntry) {
            foreach ($entry->members as $index => $member) {
                $problems[] = $missing(ReferenceCatalogType::IndicationNormalized, $member, sprintf('%s.members[%d]', $path, $index));
            }
        } elseif ($entry instanceof HospitalEntry) {
            $problems[] = $missing(ReferenceCatalogType::State, $entry->state, $path.'.state');
            $problems[] = $missing(ReferenceCatalogType::DispatchArea, $entry->area, $path.'.area');
            $areaState = $areaStates[strtolower($entry->area)] ?? null;
            if (null !== $areaState && $areaState !== strtolower($entry->state)) {
                $problems[] = new ReferenceCatalogViolation($path.'.area', 'Dispatch area belongs to another state than the hospital.');
            }
        }

        return array_values(array_filter($problems));
    }

    /**
     * Database rows of one type, keyed by lowercase public id.
     *
     * @return array<string, array{key: string, state?: string}>
     */
    private function databaseRows(ReferenceCatalogType $type): array
    {
        $rows = [];
        if (ReferenceCatalogType::DispatchArea === $type) {
            $result = $this->entityManager->createQuery('SELECT e.publicId AS id, e.name AS name, s.publicId AS state FROM '.DispatchArea::class.' e JOIN e.state s')->getArrayResult();
            foreach ($result as $row) {
                $state = $this->id($row['state']);
                $rows[$this->id($row['id'])] = ['key' => ReferenceCatalogNaturalKey::dispatchArea($state, (string) $row['name']), 'state' => $state];
            }

            return $rows;
        }

        if (ReferenceCatalogType::IndicationNormalized === $type || ReferenceCatalogType::IndicationRaw === $type) {
            $class = $type->entityClass();
            $result = $this->entityManager->createQuery('SELECT e.publicId AS id, e.code AS code, e.name AS name FROM '.$class.' e')->getArrayResult();
            foreach ($result as $row) {
                $code = (string) $row['code'];
                $name = (string) $row['name'];
                $rows[$this->id($row['id'])] = ['key' => ReferenceCatalogType::IndicationNormalized === $type
                    ? ReferenceCatalogNaturalKey::normalizedIndication($code, $name)
                    : ReferenceCatalogNaturalKey::rawIndication($code, $name)];
            }

            return $rows;
        }

        $class = $type->entityClass();
        foreach ($this->entityManager->createQuery('SELECT e.publicId AS id, e.name AS name FROM '.$class.' e')->getArrayResult() as $row) {
            $rows[$this->id($row['id'])] = ['key' => ReferenceCatalogNaturalKey::name((string) $row['name'])];
        }

        return $rows;
    }

    /**
     * @return array<string, string> alias key → owner id
     */
    private function aliasOwners(ReferenceCatalogType $type): array
    {
        $dql = ReferenceCatalogType::Department === $type
            ? 'SELECT a.name AS name, o.publicId AS owner FROM '.DepartmentAlias::class.' a JOIN a.department o'
            : 'SELECT a.name AS name, o.publicId AS owner FROM '.SpecialityAlias::class.' a JOIN a.speciality o';

        $owners = [];
        foreach ($this->entityManager->createQuery($dql)->getArrayResult() as $row) {
            $owners[ReferenceCatalogNaturalKey::name((string) $row['name'])] = $this->id($row['owner']);
        }

        return $owners;
    }

    private function id(mixed $value): string
    {
        return strtolower($value instanceof Uuid ? $value->toRfc4122() : (string) $value);
    }
}
