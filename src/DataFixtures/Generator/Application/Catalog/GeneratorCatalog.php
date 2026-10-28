<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Catalog;

use App\Allocation\Application\ReferenceCatalog\ReferenceNameKey;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Binds the generator's names and PZC codes to the rows of the installed catalog (ADR 022).
 *
 * Departments and specialities are addressed by their key (`GeneratorKeys`), lookups by their IVENA
 * value, indications by PZC code. A key resolves through the mapping file if it binds it, else by its
 * default label; names resolve the way the import resolves them: by the normalized name key and the
 * department and speciality aliases, and a name that leads to more than one row is ambiguous. Where a catalog offers several rows (normalized indications of one
 * code, raw indications of one normalized indication), the choice follows name and public id, never
 * the database id, so the same catalog content gives the same data in every installation.
 */
final class GeneratorCatalog implements ResetInterface
{
    private ?CatalogMapping $mapping = null;

    /** @var array<string, array{names: array<int, string>, keys: array<string, array<int, string>>}> */
    private array $types = [];

    /** @var array<string, CatalogMatch> */
    private array $matches = [];

    /** @var array<string, list<array{id: int, name: string}>>|null PZC code → normalized indications */
    private ?array $normalizedByCode = null;

    /** @var array<int, list<array{id: int, name: string}>> normalized id → raw indications mapped to it */
    private array $rawByNormalized = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(param: 'fixtures.catalog_mapping')]
        private readonly string $mappingPath = '',
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir = '',
    ) {
    }

    /**
     * Replaces the configured mapping, for example with the file of `app:generate:check-catalog --mapping`.
     */
    public function useMapping(CatalogMapping $mapping): void
    {
        $this->reset();
        $this->mapping = $mapping;
    }

    /**
     * @throws \InvalidArgumentException when the configured mapping file is invalid
     */
    public function mapping(): CatalogMapping
    {
        $path = trim($this->mappingPath);
        if ('' !== $path && !str_starts_with($path, '/')) {
            $path = rtrim($this->projectDir, '/').'/'.$path;
        }

        return $this->mapping ??= '' === $path ? CatalogMapping::none() : CatalogMapping::fromFile($path);
    }

    /**
     * @param string $name department or speciality key (or its default label), lookup value
     *
     * @throws \InvalidArgumentException for a department or speciality without a key
     */
    public function match(CatalogLookupType $type, string $name): CatalogMatch
    {
        $key = GeneratorKeys::key($type, $name);
        $target = $this->mapping()->name($type, $key);

        return $this->matches[$type->value."\0".$key] ??= null !== $target
            ? $this->matchTarget($type, $key, $target, 'mapping')
            : $this->matchTarget($type, $key, GeneratorKeys::label($type, $key), null);
    }

    /**
     * The speciality a department is written with: the mapping's choice, else the speciality of its key.
     *
     * @param string $department department key or its default label
     *
     * @throws \InvalidArgumentException for a department without a key
     */
    public function matchSpecialityOf(string $department): CatalogMatch
    {
        $key = GeneratorKeys::department($department);
        $target = $this->mapping()->specialityOfDepartment[$key] ?? null;
        if (null !== $target) {
            return $this->matches["speciality-of\0".$key] ??= $this->matchTarget(CatalogLookupType::Speciality, $key, $target, 'mapping');
        }

        $speciality = GeneratorKeys::DEPARTMENTS[$key]['speciality'];

        return $this->match(CatalogLookupType::Speciality, $speciality)->for($key, sprintf('it is written with the speciality "%s"', $speciality));
    }

    /**
     * The normalized indication of a PZC code (`id`) and a raw indication mapped to it (`rawId`).
     */
    public function matchIndication(string $code): CatalogMatch
    {
        return $this->matches["indication\0".$code] ??= $this->resolveIndication($code);
    }

    /**
     * @throws CatalogMismatchException
     */
    public function id(CatalogLookupType $type, string $name): int
    {
        return $this->require($this->match($type, $name), $type->value)->id ?? throw new \LogicException('A resolved match has an id.');
    }

    /**
     * @throws CatalogMismatchException
     */
    public function specialityIdOf(string $department): int
    {
        return $this->require($this->matchSpecialityOf($department), 'speciality of department')->id ?? throw new \LogicException('A resolved match has an id.');
    }

    /**
     * @return array{normalized: int, raw: int}
     *
     * @throws CatalogMismatchException
     */
    public function indicationIds(string $code): array
    {
        $match = $this->require($this->matchIndication($code), 'PZC code');
        if (null === $match->id || null === $match->rawId) {
            throw new \LogicException('A resolved indication has a normalized and a raw id.');
        }

        return ['normalized' => $match->id, 'raw' => $match->rawId];
    }

    /**
     * The first row of a catalog type by name and public id: the same row for the same catalog content.
     *
     * @param class-string $class
     *
     * @throws CatalogMismatchException when the type has no rows
     */
    public function firstId(string $class): int
    {
        $metadata = $this->entityManager->getClassMetadata($class);
        $order = $metadata->hasField('publicId') ? 'name, public_id' : 'name';
        $id = $this->entityManager->getConnection()->fetchOne(sprintf('SELECT id FROM %s ORDER BY %s LIMIT 1', $metadata->getTableName(), $order));
        if (false === $id || null === $id) {
            throw new CatalogMismatchException(sprintf('The catalog has no rows of %s.', $class));
        }

        return (int) $id;
    }

    #[\Override]
    public function reset(): void
    {
        $this->mapping = null;
        $this->types = [];
        $this->matches = [];
        $this->normalizedByCode = null;
        $this->rawByNormalized = [];
    }

    private function require(CatalogMatch $match, string $what): CatalogMatch
    {
        if (!$match->isResolved()) {
            throw new CatalogMismatchException(sprintf('Cannot resolve %s "%s": %s Run app:generate:check-catalog for every problem.', $what, $match->name, $match->problem ?? 'no reason given.'));
        }

        return $match;
    }

    private function matchTarget(CatalogLookupType $type, string $name, string $target, ?string $via): CatalogMatch
    {
        $rows = $this->rows($type);
        $candidates = $rows['keys'][ReferenceNameKey::normalize($target)] ?? [];

        if ([] === $candidates) {
            return CatalogMatch::missing($name, 'mapping' === $via
                ? sprintf('the mapping binds it to "%s", which is not a %s of the catalog.', $target, $type->value)
                : sprintf('no %s of the catalog has the name "%s"%s; bind "%s" to a catalog name in the mapping file (names.%s).', $type->value, $target, null !== $type->alias() ? ' or an alias of it' : '', $name, $type->value));
        }
        if (\count($candidates) > 1) {
            $names = array_values($candidates);
            sort($names, \SORT_STRING);

            return CatalogMatch::ambiguous($name, $names, sprintf('"%s" matches %d %s rows by name or alias (%s); the catalog has to tell them apart.', $target, \count($names), $type->value, implode(', ', $names)));
        }

        $id = array_key_first($candidates);
        $catalogName = $rows['names'][$id];

        return CatalogMatch::resolved($name, $id, $catalogName, $via ?? (ReferenceNameKey::normalize($catalogName) === ReferenceNameKey::normalize($target) ? 'name' : 'alias'));
    }

    private function resolveIndication(string $code): CatalogMatch
    {
        $this->loadIndications();
        $pinned = $this->mapping()->indications[$code] ?? [];
        $normalized = $this->normalizedByCode[$code] ?? [];
        if ([] === $normalized) {
            return CatalogMatch::missing($code, 'the catalog has no normalized indication with this PZC code.');
        }

        $warning = null;
        if (isset($pinned['normalized'])) {
            $normalized = $this->withName($normalized, $pinned['normalized']);
            if ([] === $normalized) {
                return CatalogMatch::missing($code, sprintf('the mapping binds it to the normalized indication "%s", which the catalog does not have with this code.', $pinned['normalized']));
            }
        } elseif (\count($normalized) > 1) {
            $warning = sprintf('%d normalized indications have this code (%s); using "%s". Pin one in the mapping file (indications.%s.normalized).', \count($normalized), implode(', ', array_column($normalized, 'name')), $normalized[0]['name'], $code);
        }
        $chosen = $normalized[0];

        $raw = $this->rawByNormalized[$chosen['id']] ?? [];
        if (isset($pinned['raw'])) {
            $raw = $this->withName($raw, $pinned['raw']);
            if ([] === $raw) {
                return CatalogMatch::missing($code, sprintf('the mapping binds it to the raw indication "%s", which is not mapped to "%s".', $pinned['raw'], $chosen['name']));
            }
        }
        if ([] === $raw) {
            return CatalogMatch::missing($code, sprintf('no raw indication is mapped to "%s"; map one in the catalog.', $chosen['name']));
        }
        // The raw label with the official wording first, else the first label.
        $sameWording = $this->withName($raw, $chosen['name']);
        $rawChosen = $sameWording[0] ?? $raw[0];

        return CatalogMatch::resolved($code, $chosen['id'], $chosen['name'], isset($pinned['normalized']) ? 'mapping' : 'code', $warning, $rawChosen['id'], $rawChosen['name']);
    }

    /**
     * @param list<array{id: int, name: string}> $rows
     *
     * @return list<array{id: int, name: string}>
     */
    private function withName(array $rows, string $name): array
    {
        $key = ReferenceNameKey::normalize($name);

        return array_values(array_filter($rows, static fn (array $row): bool => ReferenceNameKey::normalize($row['name']) === $key));
    }

    /**
     * @return array{names: array<int, string>, keys: array<string, array<int, string>>}
     */
    private function rows(CatalogLookupType $type): array
    {
        if (isset($this->types[$type->value])) {
            return $this->types[$type->value];
        }

        $connection = $this->entityManager->getConnection();
        $table = $this->entityManager->getClassMetadata($type->entityClass())->getTableName();
        $names = [];
        $keys = [];
        /** @var list<array{id: int|string, name: string}> $rows */
        $rows = $connection->fetchAllAssociative(sprintf('SELECT id, name FROM %s ORDER BY name, public_id', $table));
        foreach ($rows as $row) {
            $names[(int) $row['id']] = $row['name'];
            $keys[ReferenceNameKey::normalize($row['name'])][(int) $row['id']] = $row['name'];
        }

        $alias = $type->alias();
        if (null !== $alias) {
            /** @var list<array{normalized_name: string, owner_id: int|string}> $aliases */
            $aliases = $connection->fetchAllAssociative(sprintf('SELECT normalized_name, %s AS owner_id FROM %s ORDER BY normalized_name', $alias['foreignKey'], $alias['table']));
            foreach ($aliases as $alias) {
                $owner = (int) $alias['owner_id'];
                $keys[$alias['normalized_name']][$owner] = $names[$owner] ?? (string) $owner;
            }
        }

        return $this->types[$type->value] = ['names' => $names, 'keys' => $keys];
    }

    private function loadIndications(): void
    {
        if (null !== $this->normalizedByCode) {
            return;
        }

        $connection = $this->entityManager->getConnection();
        $normalizedTable = $this->entityManager->getClassMetadata(IndicationNormalized::class)->getTableName();
        $rawTable = $this->entityManager->getClassMetadata(IndicationRaw::class)->getTableName();

        $this->normalizedByCode = [];
        /** @var list<array{id: int|string, code: int|string, name: string}> $rows */
        $rows = $connection->fetchAllAssociative(sprintf('SELECT id, code, name FROM %s ORDER BY code, name, public_id', $normalizedTable));
        foreach ($rows as $row) {
            $this->normalizedByCode[str_pad((string) $row['code'], 3, '0', \STR_PAD_LEFT)][] = ['id' => (int) $row['id'], 'name' => $row['name']];
        }

        $this->rawByNormalized = [];
        /** @var list<array{id: int|string, normalized_id: int|string, name: string}> $raws */
        $raws = $connection->fetchAllAssociative(sprintf('SELECT id, normalized_id, name FROM %s WHERE normalized_id IS NOT NULL ORDER BY name, public_id', $rawTable));
        foreach ($raws as $raw) {
            $this->rawByNormalized[(int) $raw['normalized_id']][] = ['id' => (int) $raw['id'], 'name' => $raw['name']];
        }
    }
}
