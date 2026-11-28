<?php

declare(strict_types=1);

namespace App\Tests\Admin\Integration\JobRun;

use App\Admin\Application\Service\JobRun\JobRunFormatter;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Shared\Infrastructure\Monitoring\JobRun\Entity\JobRun;
use App\Shared\Infrastructure\Monitoring\JobRun\JobRunRecorder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Translation\TranslatableInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The metrics table of the job run detail page (ADR 018) with the hospital names of the database; the page itself is
 * rendered in JobRunCrudControllerTest.
 */
#[ResetDatabase]
final class JobRunFormatterTest extends KernelTestCase
{
    use Factories;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, list<string>}>
     */
    public static function metricsPerJob(): iterable
    {
        yield 'allocation import' => ['allocation_import',
            ['rows_total' => 12_345, 'rows_ok' => 12_000, 'rows_rejected' => 345, 'rows_deduplicated' => 3, 'chunks' => 50, 'read_ms' => 1_500, 'map_ms' => 800, 'persist_ms' => 65_000],
            ['12 345', '12 000', '345', '3', '50', '1.5 s', '800 ms', '1 min 05 s']];
        yield 'closure import' => ['closure_import',
            ['rows_total' => 15, 'rows_ok' => 15, 'rows_rejected' => 0, 'chunks' => 1, 'read_ms' => 10, 'map_ms' => 20, 'persist_ms' => 30],
            ['15', '15', '0', '1', '10 ms', '20 ms', '30 ms']];
        yield 'encounter import' => ['encounter_import',
            ['rows_total' => 2_500, 'rows_ok' => 2_490, 'rows_rejected' => 10, 'rows_deduplicated' => 400],
            ['2 500', '2 490', '10', '400']];
        yield 'projection rebuild' => ['allocation_projection_rebuild',
            ['imports' => 1, 'rows_deleted' => 10, 'rows_written' => 20, 'mv_refreshed' => true, 'change_detection_ms' => 5, 'delete_ms' => 6, 'insert_ms' => 7, 'closure_request_ms' => 8, 'mv_refresh_ms' => 2_000],
            ['1', '10', '20', '✓', '5 ms', '6 ms', '7 ms', '8 ms', '2.0 s']];
        yield 'full projection rebuild' => ['allocation_projection_full_rebuild',
            ['imports' => 1_120, 'rows_deleted' => 0, 'rows_written' => 900_000, 'mv_refreshed' => false, 'truncate_ms' => 50, 'rebuild_imports_ms' => 600_000, 'closure_request_ms' => 3, 'mv_refresh_ms' => 4],
            ['1 120', '0', '900 000', '✗', '50 ms', '10 min 00 s', '3 ms', '4 ms']];
        yield 'closure rebuild' => ['closure_rebuild',
            ['full' => false, 'passes' => 1, 'events' => 300, 'partitions' => 4, 'hours_written' => 7_000, 'publish_ms' => 120, 'requests' => 2, 'queries' => 90, 'reference_window' => 'P12M'],
            ['✗', '1', '300', '4', '7 000', '120 ms', '2', '90', 'P12M']];
    }

    /**
     * @param array<string, mixed> $metrics
     * @param list<string>         $expected
     */
    #[DataProvider('metricsPerJob')]
    public function testTheMetricsOfEveryJobAreFormattedInTheOrderOfTheJob(string $job, array $metrics, array $expected): void
    {
        $run = $this->record($job, $metrics);

        $rows = $this->formatter()->metricsRows($run);

        self::assertSame(array_keys($metrics), array_column($rows, 'key'));
        self::assertSame($expected, array_column($rows, 'value'));
    }

    public function testKnownKeysComeFirstAndUnknownKeysFollowSortedAsText(): void
    {
        $run = $this->record('closure_rebuild', [
            'zeta' => ['a' => 1],
            'queries' => 90,
            'full' => false,
            'custom_ms' => 1_500,
            'publish_ms' => null,
        ]);

        $rows = $this->formatter()->metricsRows($run);

        self::assertSame(['full', 'publish_ms', 'queries', 'custom_ms', 'zeta'], array_column($rows, 'key'));
        self::assertSame(['✗', '—', '90', '1.5 s', '{"a":1}'], array_column($rows, 'value'), 'unknown *_ms keys are durations, other values JSON');
        self::assertInstanceOf(TranslatableInterface::class, $rows[0]['label']);
        self::assertSame('zeta', $rows[4]['label']);
    }

    public function testHospitalIdsAreShownByNameAndUnknownOnesById(): void
    {
        $hospital = HospitalFactory::createOne(['name' => 'Klinikum Nord']);
        $run = $this->record('closure_rebuild', ['hospital_ids' => [$hospital->getId(), 999_999]]);

        $rows = $this->formatter()->metricsRows($run);

        self::assertSame(['Klinikum Nord, #999999'], array_column($rows, 'value'));
    }

    public function testASingleHospitalIsShownByNameOrIdOrAsADash(): void
    {
        $hospital = HospitalFactory::createOne(['name' => 'Klinikum Süd']);
        $formatter = $this->formatter();

        $names = [$formatter->hospital($hospital->getId()), $formatter->hospital(999_999), $formatter->hospital(null)];

        self::assertSame(['Klinikum Süd', '#999999', '—'], $names);
    }

    public function testHospitalChoicesKeepDuplicateNamesApart(): void
    {
        $first = HospitalFactory::createOne(['name' => 'Klinikum']);
        $second = HospitalFactory::createOne(['name' => 'Klinikum']);

        $choices = $this->formatter()->hospitalChoices();

        self::assertSame(['Klinikum' => $first->getId(), 'Klinikum (#'.$second->getId().')' => $second->getId()], $choices);
    }

    private function formatter(): JobRunFormatter
    {
        return self::getContainer()->get(JobRunFormatter::class);
    }

    /**
     * @param array<string, mixed> $metrics
     */
    private function record(string $job, array $metrics): JobRun
    {
        $connection = self::getContainer()->get(Connection::class);
        $recorder = new JobRunRecorder($connection, new MockClock('2026-10-25 12:00:00'), new NullLogger());
        $recorder->start('run-1', $job, 'console');
        $recorder->finish('run-1', $job, 'succeeded', 1_000, 1_048_576, [], $metrics);

        return self::getContainer()->get(EntityManagerInterface::class)->find(JobRun::class, 'run-1') ?? throw new \LogicException('job run not recorded');
    }
}
