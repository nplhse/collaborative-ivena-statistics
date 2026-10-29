<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator\Dataset;

use App\Allocation\Domain\Entity\Hospital;
use App\DataFixtures\FixtureVolumeResolver;
use App\DataFixtures\Generator\Application\Dataset\DatasetGenerator;
use App\DataFixtures\Generator\Application\Dataset\DatasetHospitalPlan;
use App\DataFixtures\Generator\Application\Dataset\DatasetPeriod;
use App\DataFixtures\Generator\Application\Dataset\DatasetProgress;
use App\DataFixtures\Generator\Application\Dataset\DatasetRequest;
use App\DataFixtures\Generator\Application\Dataset\ExistingData;
use App\DataFixtures\Generator\Application\GeneratedMonth;
use App\DataFixtures\Generator\Application\HospitalMonthGenerator;
use App\DataFixtures\Generator\Application\ProfileSelection;
use App\DataFixtures\Generator\Application\Run\GenerationRunScope;
use App\DataFixtures\Generator\Application\SyntheticImportMarker;
use Doctrine\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader;
use Doctrine\Bundle\FixturesBundle\Purger\ORMPurgerFactory;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The dataset engine (#877) on the test preset `minimal`: three active hospitals with data in August and
 * September 2026, further participating hospitals with an owner and no data. Background messages are handled at
 * once in tests, so a run is projected and finished when generate() returns.
 */
#[ResetDatabase]
final class DatasetGeneratorTest extends KernelTestCase
{
    private const string UNTIL = '2026-09-30';

    protected function setUp(): void
    {
        $_ENV['FIXTURES_UNTIL'] = $_SERVER['FIXTURES_UNTIL'] = self::UNTIL;
        self::bootKernel();
        $this->load();
    }

    protected function tearDown(): void
    {
        unset($_ENV['FIXTURES_UNTIL'], $_SERVER['FIXTURES_UNTIL']);
        parent::tearDown();
    }

    public function testTheScopeAllTakesOnlyParticipatingHospitalsAndBlocksWhatIsMissing(): void
    {
        $participating = $this->ids('SELECT id FROM hospital WHERE is_participating ORDER BY id');
        $other = (int) $this->connection()->fetchOne('SELECT id FROM hospital WHERE NOT is_participating ORDER BY id LIMIT 1');
        self::assertNotSame([], $participating);
        self::assertGreaterThan(0, $other);

        $all = $this->datasets()->prepare(new DatasetRequest(GenerationRunScope::AllParticipating, [], DatasetPeriod::between('2026-06', '2026-07')));
        $planned = array_map(static fn (DatasetHospitalPlan $h): int => $h->hospitalId, $all->hospitals);
        sort($planned);
        self::assertSame($participating, $planned, 'only hospitals marked as participating');
        self::assertTrue($all->isRunnable(), implode(' ', $all->runBlockers()));

        $ownerless = $participating[0];
        $this->connection()->executeStatement('UPDATE hospital SET owner_id = NULL WHERE id = :id', ['id' => $ownerless]);
        $this->entityManager()->clear();
        $selected = $this->datasets()->prepare(new DatasetRequest(GenerationRunScope::Selected, [$other, $ownerless], DatasetPeriod::between('2026-06', '2026-07')));
        self::assertFalse($selected->isRunnable());
        self::assertStringContainsString('does not take part', implode(' ', $selected->hospitals[0]->blockers));
        self::assertStringContainsString('has no owner', implode(' ', $selected->hospitals[1]->blockers));

        $active = $this->activeHospital();
        $refused = $this->datasets()->prepare(new DatasetRequest(GenerationRunScope::One, [$active], DatasetPeriod::between('2026-07', '2026-08')));
        self::assertSame(['2026-07'], $refused->hospitals[0]->generate);
        self::assertStringContainsString('already has generated data in 2026-08; choose fill or replace', implode(' ', $refused->hospitals[0]->blockers), 'Refuse is the default');

        $this->makeReal($active, '2026-09');
        foreach (ExistingData::cases() as $mode) {
            $plan = $this->datasets()->prepare(new DatasetRequest(GenerationRunScope::One, [$active], DatasetPeriod::between('2026-08', '2026-09'), existing: $mode));
            self::assertStringContainsString('real imports in 2026-09', implode(' ', $plan->hospitals[0]->blockers), $mode->value.' never mixes with real data');
        }

        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM generation_run'), 'prepare writes nothing');
    }

    public function testHospitalProfilesGiveDifferentDatasetsAndTheRunProjectsThem(): void
    {
        [$small, $large] = $this->hospitalsWithoutData(2);
        $request = new DatasetRequest(
            GenerationRunScope::Selected,
            [$small, $large],
            DatasetPeriod::between('2026-06', '2026-07'),
            selections: [
                // 3,650 a year are 10 a day, 36,500 are 100: June and July have 61 days.
                $small => new ProfileSelection(cathlab: false, annualVolume: 3650),
                $large => new ProfileSelection(annualVolume: 36500, closureShare: 0.1),
            ],
        );
        $plan = $this->datasets()->prepare($request);
        self::assertTrue($plan->isRunnable(), implode(' ', $plan->runBlockers()));

        $runId = $this->datasets()->start($plan);
        $result = $this->datasets()->generate($runId);

        self::assertSame([$small, $large], $result->handedOver);
        self::assertFalse($result->hasFailures());
        $run = $result->run;
        self::assertNotNull($run);
        self::assertSame(['completed', 'done'], [$run->status->value, $run->stage->value]);
        self::assertSame(['2026-06', '2026-07'], [$run->fromMonth, $run->toMonth]);
        self::assertSame(['annual_volume' => 3650, 'cathlab' => false], $this->sorted($run->settings['selections'][(string) $small] ?? []), 'the run records the profile');
        self::assertSame(['refuse', '2026-07-31', 'dataset'], [$run->settings['existing'], $run->settings['last_day'], $run->settings['engine']]);

        $smallCount = $this->rows('allocation', $small);
        $largeCount = $this->rows('allocation', $large);
        self::assertEqualsWithDelta(610, $smallCount, 150, 'about 10 a day');
        self::assertEqualsWithDelta(10.0, $largeCount / $smallCount, 2.5, 'ten times the volume');
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM allocation WHERE hospital_id = :id AND requires_cathlab', ['id' => $small]), 'no cath lab, no cath lab cases');
        self::assertGreaterThan($this->rows('closure_interval', $small), $this->rows('closure_interval', $large), '10 % closed department-hours instead of 1 %');

        foreach ([$small, $large] as $hospital) {
            self::assertSame($this->rows('allocation', $hospital), $this->rows('allocation_stats_projection', $hospital), 'every allocation is projected');
            self::assertSame(2, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM import WHERE hospital_id = :id AND file_path LIKE 'synthetic/allocations/%'", ['id' => $hospital]));
        }

        $again = $this->datasets()->prepare($request);
        self::assertFalse($again->isRunnable(), 'a repeated run refuses by default');
        $fill = $this->datasets()->prepare(new DatasetRequest(GenerationRunScope::Selected, [$small, $large], DatasetPeriod::between('2026-06', '2026-07'), existing: ExistingData::Fill));
        self::assertSame(['2026-06', '2026-07'], $fill->hospitals[0]->keep);
        self::assertStringContainsString('nothing to generate', implode(' ', $fill->runBlockers()), 'fill on complete months writes nothing');
    }

    public function testReplacingGeneratedDataGivesTheSameRowsAndKeepsRealAndOtherData(): void
    {
        $hospital = $this->activeHospital();
        $publicId = (string) $this->connection()->fetchOne('SELECT public_id FROM hospital WHERE id = :id', ['id' => $hospital]);
        $real = $this->makeReal($hospital, '2026-09');
        $realRows = $this->connection()->fetchOne('SELECT md5(string_agg(id::text, \',\' ORDER BY id)) FROM allocation WHERE import_id = :id', ['id' => $real]);
        $before = $this->monthFingerprint($hospital, '2026-08');
        $others = $this->connection()->fetchOne('SELECT md5(string_agg(id::text, \',\' ORDER BY id)) FROM allocation WHERE hospital_id <> :id', ['id' => $hospital]);
        $othersProjected = (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM allocation_stats_projection WHERE hospital_id <> :id', ['id' => $hospital]);
        $mci = (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM mci_case m JOIN import i ON i.id = m.import_id WHERE m.hospital_id = :id AND i.file_path LIKE 'synthetic/allocations/%'", ['id' => $hospital]);
        $scenario = $this->addScenarioImport($hospital, $publicId, '2026-08-14');
        $oldImports = $this->ids("SELECT id FROM import WHERE hospital_id = :id AND file_path LIKE 'synthetic/%' AND file_path LIKE '%2026-08%'", ['id' => $hospital]);

        $plan = $this->datasets()->prepare(new DatasetRequest(GenerationRunScope::One, [$hospital], DatasetPeriod::between('2026-08', '2026-08'), existing: ExistingData::Replace));
        self::assertTrue($plan->isRunnable(), implode(' ', $plan->runBlockers()));
        self::assertSame(['2026-08'], $plan->hospitals[0]->replace);
        self::assertSame([$scenario], $plan->hospitals[0]->scenarioImports, 'the scenario overlay of the period goes too');

        $result = $this->datasets()->generate($this->datasets()->start($plan));

        self::assertFalse($result->hasFailures());
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM import WHERE id IN ('.implode(',', $oldImports).')'), 'old generated and scenario imports are gone');
        self::assertSame($before, $this->monthFingerprint($hospital, '2026-08'), 'same seed, same rows');
        self::assertSame($realRows, $this->connection()->fetchOne('SELECT md5(string_agg(id::text, \',\' ORDER BY id)) FROM allocation WHERE import_id = :id', ['id' => $real]), 'the real import is untouched');
        self::assertSame($others, $this->connection()->fetchOne('SELECT md5(string_agg(id::text, \',\' ORDER BY id)) FROM allocation WHERE hospital_id <> :id', ['id' => $hospital]), 'other hospitals are untouched');
        self::assertSame($othersProjected, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM allocation_stats_projection WHERE hospital_id <> :id', ['id' => $hospital]));
        self::assertSame($mci, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM mci_case m JOIN import i ON i.id = m.import_id WHERE m.hospital_id = :id AND i.file_path LIKE 'synthetic/allocations/%'", ['id' => $hospital]), 'MCI cases are replaced, not added');
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM mci_case m LEFT JOIN import i ON i.id = m.import_id WHERE i.id IS NULL'), 'no MCI case is left without its import');
        self::assertSame($this->rows('allocation', $hospital), $this->rows('allocation_stats_projection', $hospital), 'the hospital is projected again');
        $this->assertKpisMatchTheUploads();
    }

    /**
     * The worker must not rebuild the closure analysis of a hospital while the generator deletes and writes its
     * imports (deadlock on closure_analysis_source, #791): closure rebuilds wait until the hospital is written,
     * and the rebuilds its deletions requested are done by the run afterwards.
     */
    public function testClosureRebuildsWaitWhileAHospitalIsReplacedAndFollowAfterwards(): void
    {
        $hospital = $this->activeHospital();
        $plan = $this->datasets()->prepare(new DatasetRequest(GenerationRunScope::One, [$hospital], DatasetPeriod::between('2026-08', '2026-09'), existing: ExistingData::Replace));
        self::assertSame(['2026-08', '2026-09'], $plan->hospitals[0]->replace);

        $locks = self::getContainer()->get(LockFactory::class);
        $probe = new class($locks) implements DatasetProgress {
            /** @var list<bool> whether another holder could take the closure rebuild lock, per written month */
            public array $lockFree = [];

            public function __construct(private readonly LockFactory $locks)
            {
            }

            public function hospitalStarted(DatasetHospitalPlan $hospital): void
            {
            }

            public function monthGenerated(DatasetHospitalPlan $hospital, string $month, GeneratedMonth $generated): void
            {
                $lock = $this->locks->createLock('closure-volume-projection', null);
                $this->lockFree[] = $lock->acquire(false);
                $lock->release();
            }

            public function hospitalFinished(DatasetHospitalPlan $hospital): void
            {
            }

            public function hospitalFailed(DatasetHospitalPlan $hospital, string $message): void
            {
            }
        };
        $result = $this->datasets()->generate($this->datasets()->start($plan), $probe);

        self::assertFalse($result->hasFailures());
        self::assertSame([false, false], $probe->lockFree, 'no closure rebuild can start while the months are replaced');
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM closure_rebuild_request WHERE consumed_at IS NULL AND hospital_id = :id', ['id' => $hospital]), 'the requests of the deletions are done by the run');
        // The analysis rows of the old closures went with their imports; rows for the new ones exist only when the
        // rebuild ran after the months were written.
        self::assertGreaterThan(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM closure_analysis_source s JOIN closure_interval c ON c.id = s.closure_interval_id WHERE c.hospital_id = :id', ['id' => $hospital]), 'the closures of the new months are analysed');
    }

    public function testAFailedHospitalIsRolledBackAndARetryCompletesItWithoutDuplicates(): void
    {
        [$hospital] = $this->hospitalsWithoutData(1);
        $plan = $this->datasets()->prepare(new DatasetRequest(GenerationRunScope::One, [$hospital], DatasetPeriod::between('2026-06', '2026-07'), selections: [$hospital => new ProfileSelection(annualVolume: 3650)]));
        $runId = $this->datasets()->start($plan);

        $failing = new class implements DatasetProgress {
            public function hospitalStarted(DatasetHospitalPlan $hospital): void
            {
            }

            public function monthGenerated(DatasetHospitalPlan $hospital, string $month, GeneratedMonth $generated): void
            {
                throw new \RuntimeException('Simulated failure after '.$month);
            }

            public function hospitalFinished(DatasetHospitalPlan $hospital): void
            {
            }

            public function hospitalFailed(DatasetHospitalPlan $hospital, string $message): void
            {
            }
        };
        $failed = $this->datasets()->generate($runId, $failing);

        self::assertTrue($failed->hasFailures());
        self::assertSame([], $failed->handedOver);
        self::assertSame(['failed', 'generation_failed', 0], [$failed->run?->hospitals[0]->status->value, $failed->run?->hospitals[0]->reasonCode, $failed->run?->hospitals[0]->months]);
        self::assertStringContainsString('after 2026-06', (string) $failed->run?->hospitals[0]->error);
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM import WHERE hospital_id = :id', ['id' => $hospital]), 'the written month was rolled back');

        $tester = new CommandTester(new Application(self::$kernel)->find('app:generate:dataset'));
        self::assertSame(Command::SUCCESS, $tester->execute(['--retry' => $runId]), $tester->getDisplay());

        $retry = $this->connection()->fetchAssociative("SELECT id, status, settings->>'retry_of' AS retry_of, settings->>'existing' AS existing, settings->'selections' AS selections FROM generation_run WHERE id <> :run", ['run' => $runId]);
        self::assertIsArray($retry);
        self::assertSame(['completed', $runId, 'fill', '{"'.$hospital.'": {"annual_volume": 3650}}'], [$retry['status'], $retry['retry_of'], $retry['existing'], $retry['selections']]);
        self::assertSame(2, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM import WHERE hospital_id = :id AND file_path LIKE 'synthetic/allocations/%'", ['id' => $hospital]));
        self::assertSame($this->rows('allocation', $hospital), (int) $this->connection()->fetchOne("SELECT SUM(row_count) FROM import WHERE hospital_id = :id AND file_path LIKE 'synthetic/allocations/%'", ['id' => $hospital]), 'no duplicates');
        self::assertSame(Command::FAILURE, $tester->execute(['--retry' => $runId]), 'the failed hospitals of a run are retried once: the second retry finds them complete');
    }

    public function testFillKeepsCompleteMonthsAndProjectsThoseLeftUnprojected(): void
    {
        [$hospital] = $this->hospitalsWithoutData(1);
        // A month written by a run whose process stopped before its asynchronous stage: complete, not projected.
        $container = self::getContainer();
        /** @var HospitalMonthGenerator $generator */
        $generator = $container->get(HospitalMonthGenerator::class);
        /** @var FixtureVolumeResolver $volumes */
        $volumes = $container->get(FixtureVolumeResolver::class);
        $volume = $volumes->resolve();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $entity = $entityManager->find(Hospital::class, $hospital);
        self::assertNotNull($entity);
        $kept = $generator->generate($volume, $entity, '2026-06', $volume->lastDay(new MockClock()));
        $entityManager->clear();
        self::assertSame(0, $this->rows('allocation_stats_projection', $hospital));

        $plan = $this->datasets()->prepare(new DatasetRequest(GenerationRunScope::One, [$hospital], DatasetPeriod::between('2026-06', '2026-07'), existing: ExistingData::Fill));
        self::assertSame([['2026-07'], ['2026-06']], [$plan->hospitals[0]->generate, $plan->hospitals[0]->keep]);
        $result = $this->datasets()->generate($this->datasets()->start($plan));

        self::assertSame(1, $result->run?->hospitals[0]->months, 'the kept month is not generated again');
        self::assertContains($kept->importId, $result->run->hospitals[0]->allocationImportIds);
        self::assertSame($this->rows('allocation', $hospital), $this->rows('allocation_stats_projection', $hospital), 'the kept month is projected as well');
    }

    public function testTheCommandNeedsAScopeAndADryRunWritesNothing(): void
    {
        [$ready] = $this->hospitalsWithoutData(1);
        $readyKey = (string) $this->connection()->fetchOne('SELECT public_id FROM hospital WHERE id = :id', ['id' => $ready]);
        $outside = (string) $this->connection()->fetchOne('SELECT public_id FROM hospital WHERE NOT is_participating ORDER BY id LIMIT 1');
        $imports = (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM import');
        $tester = new CommandTester(new Application(self::$kernel)->find('app:generate:dataset'));

        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertSame(Command::INVALID, $tester->execute(['--hospital' => [$readyKey], '--all-participating' => true]));
        self::assertSame(Command::INVALID, $tester->execute(['--hospital' => [$readyKey, $outside], '--annual-volume' => 1000]), 'profile options take one hospital');

        $options = ['--hospital' => [$readyKey, $outside], '--from' => '2026-06', '--to' => '2026-07', '--dry-run' => true];
        self::assertSame(Command::FAILURE, $tester->execute($options));
        self::assertStringContainsString('does not take part', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->execute([...$options, '--skip-blocked' => true]), $tester->getDisplay());
        self::assertStringContainsString('Skipping 1 blocked hospital', $tester->getDisplay());
        self::assertStringContainsString('2026-06 – 2026-07 (2)', $tester->getDisplay());

        self::assertSame($imports, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM import'));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM generation_run'));

        self::assertSame(Command::SUCCESS, $tester->execute(['--hospital' => [$readyKey], '--from' => '2026-07', '--to' => '2026-07', '--annual-volume' => 3650]), $tester->getDisplay());
        self::assertStringContainsString('completed', $tester->getDisplay());
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM import WHERE hospital_id = :id AND file_path LIKE 'synthetic/allocations/%'", ['id' => $ready]));
    }

    private function datasets(): DatasetGenerator
    {
        /** @var DatasetGenerator $datasets */
        $datasets = self::getContainer()->get(DatasetGenerator::class);

        return $datasets;
    }

    private function activeHospital(): int
    {
        return (int) $this->connection()->fetchOne("SELECT hospital_id FROM import WHERE file_path LIKE 'synthetic/allocations/%2026-08.csv' ORDER BY file_path LIMIT 1");
    }

    /**
     * @return list<int>
     */
    private function hospitalsWithoutData(int $count): array
    {
        $ids = $this->ids('SELECT h.id FROM hospital h WHERE h.is_participating AND h.owner_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM import i WHERE i.hospital_id = h.id) ORDER BY h.id LIMIT '.$count);
        self::assertCount($count, $ids);

        return $ids;
    }

    /**
     * Turns the generated allocation import of a month into a "real" one, as an upload would be.
     */
    private function makeReal(int $hospital, string $month): int
    {
        $id = (int) $this->connection()->fetchOne('SELECT id FROM import WHERE hospital_id = :id AND file_path LIKE :marker', ['id' => $hospital, 'marker' => 'synthetic/allocations/%/'.$month.'.csv']);
        self::assertGreaterThan(0, $id);
        $this->connection()->executeStatement("UPDATE import SET file_path = 'imports/upload-'||id||'.csv' WHERE id = :id AND file_path LIKE 'synthetic/allocations/%'", ['id' => $id]);

        return $id;
    }

    private function addScenarioImport(int $hospital, string $publicId, string $day): int
    {
        $template = (int) $this->connection()->fetchOne("SELECT id FROM import WHERE hospital_id = :id AND file_path LIKE 'synthetic/allocations/%2026-08.csv'", ['id' => $hospital]);
        $id = (int) $this->connection()->fetchOne(
            "INSERT INTO import (name, created_at, hospital_id, status, type, file_path, file_extension, file_mime_type, file_size, row_count, rows_passed, rows_rejected, run_count, run_time, created_by_id)
             SELECT 'Scenario', created_at, hospital_id, status, type, :path, file_extension, file_mime_type, file_size, 0, 0, 0, run_count, run_time, created_by_id FROM import WHERE id = :id RETURNING id",
            ['path' => SyntheticImportMarker::scenarioPath('icy-road', 740, $publicId, $day), 'id' => $template],
        );
        self::assertGreaterThan(0, $id);

        return $id;
    }

    private function monthFingerprint(int $hospital, string $month): string
    {
        return (string) $this->connection()->fetchOne(
            "SELECT md5(string_agg(concat_ws('|', a.created_at, a.department_id, a.indication_normalized_id, a.urgency, a.department_was_closed), ',' ORDER BY a.created_at, a.department_id, a.indication_normalized_id, a.urgency))
             FROM allocation a JOIN import i ON i.id = a.import_id
             WHERE a.hospital_id = :id AND i.file_path LIKE :marker",
            ['id' => $hospital, 'marker' => 'synthetic/allocations/%/'.$month.'.csv'],
        );
    }

    private function rows(string $table, int $hospital): int
    {
        return (int) $this->connection()->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE hospital_id = :id', $table), ['id' => $hospital]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<int>
     */
    private function ids(string $sql, array $params = []): array
    {
        return array_map(intval(...), $this->connection()->fetchFirstColumn($sql, $params));
    }

    /**
     * @return array<mixed>
     */
    private function sorted(mixed $value): array
    {
        $value = \is_array($value) ? $value : [];
        ksort($value);

        return $value;
    }

    private function assertKpisMatchTheUploads(): void
    {
        $uploads = $this->connection()->fetchAllKeyValue('SELECT CAST(created_at AS date) AS day, SUM(row_count) FROM import GROUP BY 1 HAVING SUM(row_count) > 0 ORDER BY 1');
        $kpis = $this->connection()->fetchAllKeyValue('SELECT date, records_total FROM kpi_daily WHERE hospital_id IS NULL AND records_total > 0 ORDER BY date');

        self::assertEquals($uploads, $kpis, 'KPIs of the old and the new upload days are up to date');
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }

    private function load(): void
    {
        $container = self::getContainer();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        /** @var SymfonyFixturesLoader $loader */
        $loader = $container->get('doctrine.fixtures.loader');
        /** @var ORMPurgerFactory $purgerFactory */
        $purgerFactory = $container->get('doctrine.fixtures.purger.orm_purger_factory');

        new ORMExecutor($entityManager, $purgerFactory->createForEntityManager('default', $entityManager, []))->execute($loader->getFixtures(['allocations']), false);
        $entityManager->clear();
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }
}
