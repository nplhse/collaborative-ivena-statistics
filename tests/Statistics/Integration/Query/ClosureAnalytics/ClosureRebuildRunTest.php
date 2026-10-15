<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\ClosureAnalytics;

use App\Statistics\Application\Contract\ClosureVolumeProjectionRebuildInterface;
use App\Statistics\ClosureAnalytics\Infrastructure\Projection\ClosureVolumeProjectionRebuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class ClosureRebuildRunTest extends KernelTestCase
{
    public function testAConsumedRunIsRecordedWithItsTriggerAndCounts(): void
    {
        self::bootKernel();
        $this->insertOpenRequest('analysis', 40);
        $this->insertOpenRequest('volume', 64);

        self::getContainer()->get(ClosureVolumeProjectionRebuildInterface::class)->consume();

        $run = $this->onlyRun();
        self::assertSame('messenger', $run['triggered_by']);
        self::assertSame('succeeded', $run['status']);
        self::assertFalse($run['full_rebuild']);
        self::assertSame([40, 64], json_decode((string) $run['hospital_ids'], true));
        self::assertSame(1, (int) $run['passes']);
        self::assertSame(2, (int) $run['request_count']);
        self::assertNotNull($run['finished_at']);
        self::assertGreaterThan(0, (int) $run['peak_memory_bytes']);
        self::assertNotNull($run['publish_ms']);
    }

    public function testAFailedRunKeepsTheErrorLocally(): void
    {
        self::bootKernel();
        $this->insertOpenRequest('volume', 40);
        self::getContainer()->get(ClosureVolumeProjectionRebuilder::class)->beforePublishHook = static function (): never {
            throw new \RuntimeException('publish failed');
        };

        try {
            self::getContainer()->get(ClosureVolumeProjectionRebuildInterface::class)->consume();
            self::fail('The publish hook should abort the rebuild.');
        } catch (\RuntimeException) {
        }

        $run = $this->onlyRun();
        self::assertSame('failed', $run['status']);
        self::assertSame(\RuntimeException::class, $run['error_class']);
        self::assertSame('publish failed', $run['error_message']);
    }

    public function testAnIdleHourlyCheckRecordsNoRunAndARunningRowOfADeadProcessIsAbandoned(): void
    {
        self::bootKernel();
        $rebuild = self::getContainer()->get(ClosureVolumeProjectionRebuildInterface::class);
        $rebuild->consume(false);
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM closure_rebuild_run'));

        $this->connection()->insert('closure_rebuild_run', [
            'id' => 'killed-run',
            'triggered_by' => 'console',
            'status' => 'running',
            'full_rebuild' => 'true',
            'hospital_ids' => '[]',
            'started_at' => '2026-10-14 15:57:14',
        ]);
        $this->insertOpenRequest('volume', 40);
        $rebuild->consume(false);

        self::assertSame(
            ['abandoned', 'succeeded'],
            $this->connection()->fetchFirstColumn("SELECT status FROM closure_rebuild_run ORDER BY CASE WHEN id = 'killed-run' THEN 0 ELSE 1 END"),
        );
        self::assertSame('scheduler', $this->connection()->fetchOne("SELECT triggered_by FROM closure_rebuild_run WHERE id <> 'killed-run'"));
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyRun(): array
    {
        $runs = $this->connection()->fetchAllAssociative('SELECT * FROM closure_rebuild_run');
        self::assertCount(1, $runs);

        return $runs[0];
    }

    private function insertOpenRequest(string $kind, int $hospitalId): void
    {
        $this->connection()->insert('closure_rebuild_request', [
            'kind' => $kind,
            'hospital_id' => $hospitalId,
            'requested_at' => '2026-10-14 10:00:00',
        ]);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
