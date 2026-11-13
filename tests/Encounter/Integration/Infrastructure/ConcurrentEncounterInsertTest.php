<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Entity\EncounterTimes;
use App\Encounter\Domain\Enum\EncounterInsertOutcome;
use App\Encounter\Domain\Enum\Sex;
use App\Encounter\Infrastructure\Persistence\EncounterInsertStatement;
use App\Encounter\Infrastructure\Repository\EncounterRepository;
use App\Import\Domain\Entity\Import;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Two database sessions insert the same visit at the same time (ADR 029).
 *
 * Session 1 inserts inside an open transaction. Session 2 sends the same insert asynchronously, so it
 * really runs while session 1 holds the uncommitted row and has to wait for it. After session 1
 * commits, session 2 inserts nothing. Both use the production statement of
 * EncounterRepository::insertIfNew().
 *
 * DAMA's per-test transaction is invisible to other sessions, so this test commits a minimal hospital
 * and import through its own connection (plain SQL, no audit rows) and removes them afterwards.
 */
#[ResetDatabase]
#[RequiresPhpExtension('pgsql')]
final class ConcurrentEncounterInsertTest extends KernelTestCase
{
    private Connection $setup;

    /** @var array{user: int, state: int, dispatch_area: int, hospital: int, import: int}|null */
    private ?array $ids = null;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->setup = DriverManager::getConnection($this->connectionParams());
        $this->ids = $this->commitHospitalAndImport();
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (null !== $this->ids) {
            $this->setup->executeStatement('DELETE FROM encounter WHERE import_id = ?', [$this->ids['import']]);
            $this->setup->executeStatement('DELETE FROM import WHERE id = ?', [$this->ids['import']]);
            $this->setup->executeStatement('DELETE FROM hospital WHERE id = ?', [$this->ids['hospital']]);
            $this->setup->executeStatement('DELETE FROM dispatch_area WHERE id = ?', [$this->ids['dispatch_area']]);
            $this->setup->executeStatement('DELETE FROM state WHERE id = ?', [$this->ids['state']]);
            $this->setup->executeStatement('DELETE FROM "user" WHERE id = ?', [$this->ids['user']]);
        }

        $this->setup->close();
        parent::tearDown();
    }

    public function testTwoConcurrentInsertsOfTheSameFingerprintCreateOneEncounter(): void
    {
        self::assertNotNull($this->ids);
        $statement = EncounterInsertStatement::for($this->entityManager(), $this->visit());

        // Session 1: insert, transaction still open.
        $this->setup->beginTransaction();
        $firstId = $this->setup->executeQuery($statement->sql, $statement->params, $statement->types)->fetchOne();
        self::assertNotFalse($firstId);

        // Session 2: same insert, sent without waiting for the result.
        $second = $this->pgsqlConnection();
        self::assertTrue(pg_send_query_params($second, $this->numberedPlaceholders($statement->sql), $this->databaseValues($statement)));
        usleep(200_000);
        self::assertTrue(pg_connection_busy($second), 'Session 2 waits for the uncommitted visit of session 1.');

        $this->setup->commit();

        $result = pg_get_result($second);
        self::assertNotFalse($result);
        self::assertSame(\PGSQL_TUPLES_OK, pg_result_status($result), (string) pg_result_error($result));
        self::assertSame(0, pg_num_rows($result), 'Session 2 inserted nothing.');
        pg_close($second);

        // The application's own connection now sees the visit as known.
        self::assertSame(EncounterInsertOutcome::Duplicate, $this->repository()->insertIfNew($this->visit()));
        self::assertSame(1, $this->repository()->countByImportId($this->ids['import']));
    }

    private function visit(): Encounter
    {
        self::assertNotNull($this->ids);
        $em = $this->entityManager();

        return new Encounter(
            hospital: $em->getReference(Hospital::class, $this->ids['hospital']),
            import: $em->getReference(Import::class, $this->ids['import']),
            keyGeneration: 1,
            visitFingerprint: hash('sha256', 'visit|race'),
            caseKey: hash('sha256', 'case|race'),
            age: 70,
            sex: Sex::Male,
            times: new EncounterTimes(edAdmissionAt: new \DateTimeImmutable('2026-10-12 23:58')),
        );
    }

    /**
     * @return array{user: int, state: int, dispatch_area: int, hospital: int, import: int}
     */
    private function commitHospitalAndImport(): array
    {
        return $this->setup->transactional(static function (Connection $db): array {
            $suffix = bin2hex(random_bytes(4));
            $now = '2026-11-01 12:00:00';

            $user = (int) $db->fetchOne('INSERT INTO "user" (username, email, roles, password, created_at) VALUES (?, ?, ?, ?, ?) RETURNING id', ['race-'.$suffix, 'race-'.$suffix.'@example.test', '[]', 'x', $now]);
            $state = (int) $db->fetchOne('INSERT INTO state (public_id, name, created_at, created_by_id) VALUES (gen_random_uuid(), ?, ?, ?) RETURNING id', ['Race state '.$suffix, $now, $user]);
            $area = (int) $db->fetchOne('INSERT INTO dispatch_area (public_id, name, state_id, created_at, created_by_id) VALUES (gen_random_uuid(), ?, ?, ?, ?) RETURNING id', ['Race area '.$suffix, $state, $now, $user]);
            $hospital = (int) $db->fetchOne(
                'INSERT INTO hospital (public_id, name, location, size, beds, created_at, address_street, address_postal_code, address_city, address_state, address_country, dispatch_area_id, state_id, created_by_id)
                 VALUES (gen_random_uuid(), ?, ?, ?, 100, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                ['Race hospital '.$suffix, 'Urban', 'Small', $now, 'Street 1', '12345', 'City', 'State', 'DE', $area, $state, $user],
            );
            $import = (int) $db->fetchOne(
                'INSERT INTO import (name, status, type, file_path, file_extension, file_mime_type, file_size, row_count, run_count, run_time, created_at, hospital_id, created_by_id)
                 VALUES (?, ?, ?, ?, ?, ?, 1, 1, 1, 1, ?, ?, ?) RETURNING id',
                ['Race import '.$suffix, 'Completed', 'Closure', 'dummy/path', 'csv', 'text/csv', $now, $hospital, $user],
            );

            return ['user' => $user, 'state' => $state, 'dispatch_area' => $area, 'hospital' => $hospital, 'import' => $import];
        });
    }

    private function pgsqlConnection(): \PgSql\Connection
    {
        $params = $this->connectionParams();
        $dsn = sprintf(
            "host='%s' port='%s' dbname='%s' user='%s' password='%s'",
            (string) ($params['host'] ?? '127.0.0.1'),
            (string) ($params['port'] ?? 5432),
            (string) ($params['dbname'] ?? ''),
            (string) ($params['user'] ?? ''),
            (string) ($params['password'] ?? ''),
        );
        $connection = pg_connect($dsn, \PGSQL_CONNECT_FORCE_NEW);
        self::assertInstanceOf(\PgSql\Connection::class, $connection);

        return $connection;
    }

    private function numberedPlaceholders(string $sql): string
    {
        $position = 0;

        return (string) preg_replace_callback('/\?/', static function () use (&$position): string {
            return '$'.++$position;
        }, $sql);
    }

    /**
     * @return list<string|null>
     */
    private function databaseValues(EncounterInsertStatement $statement): array
    {
        $platform = $this->setup->getDatabasePlatform();
        $values = [];
        foreach ($statement->params as $i => $value) {
            $converted = $statement->types[$i]->convertToDatabaseValue($value, $platform);
            $values[] = match (true) {
                null === $converted => null,
                \is_bool($converted) => $converted ? 't' : 'f',
                \is_scalar($converted) => (string) $converted,
                default => throw new \LogicException('Unexpected database value for parameter '.$i.'.'),
            };
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionParams(): array
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $params = $connection->getParams();
        unset($params['dama.connection_key'], $params['dbname_suffix'], $params['idle_connection_ttl']);

        return $params;
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function repository(): EncounterRepository
    {
        $repository = self::getContainer()->get(EncounterRepository::class);
        self::assertInstanceOf(EncounterRepository::class, $repository);

        return $repository;
    }
}
