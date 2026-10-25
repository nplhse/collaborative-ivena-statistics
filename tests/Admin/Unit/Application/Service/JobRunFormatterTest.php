<?php

declare(strict_types=1);

namespace App\Tests\Admin\Unit\Application\Service;

use App\Admin\Application\Service\JobRun\JobRunFormatter;
use App\Admin\Infrastructure\Repository\HospitalNameRepository;
use App\Shared\Infrastructure\Monitoring\JobRun\Entity\JobRun;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatableInterface;

final class JobRunFormatterTest extends TestCase
{
    #[DataProvider('durations')]
    public function testDuration(?int $ms, string $expected): void
    {
        self::assertSame($expected, JobRunFormatter::duration($ms));
    }

    /**
     * @return iterable<array{?int, string}>
     */
    public static function durations(): iterable
    {
        yield [null, '—'];
        yield [0, '0 ms'];
        yield [999, '999 ms'];
        yield [1_000, '1.0 s'];
        yield [59_949, '59.9 s'];
        yield [60_000, '1 min 00 s'];
        yield [979_000, '16 min 19 s'];
        yield [3_600_000, '1 h 00 min'];
        yield [5_461_000, '1 h 31 min'];
    }

    public function testMemory(): void
    {
        self::assertSame('—', JobRunFormatter::memory(null));
        self::assertSame('106.0 MB', JobRunFormatter::memory(111_149_056));
    }

    public function testMetricsFollowTheJobOrderAndKeepUnknownKeys(): void
    {
        $formatter = new JobRunFormatter($this->hospitalNames([40 => 'Klinikum Nord']));
        $run = $this->jobRun('closure_rebuild', [
            'zeta' => ['a' => 1],
            'queries' => 90,
            'hospital_ids' => [40, 41],
            'full' => false,
            'custom_ms' => 1_500,
            'publish_ms' => null,
        ]);

        $rows = $formatter->metricsRows($run);

        self::assertSame(['full', 'hospital_ids', 'publish_ms', 'queries', 'custom_ms', 'zeta'], array_column($rows, 'key'));
        self::assertSame(['✗', 'Klinikum Nord, #41', '—', '90', '1.5 s', '{"a":1}'], array_column($rows, 'value'));
        self::assertInstanceOf(TranslatableInterface::class, $rows[0]['label']);
        self::assertSame('zeta', $rows[5]['label']);
    }

    public function testHospitalChoicesKeepDuplicateNamesApart(): void
    {
        $formatter = new JobRunFormatter($this->hospitalNames([1 => 'Klinikum', 2 => 'Klinikum', 3 => '']));

        self::assertSame(['Klinikum' => 1, 'Klinikum (#2)' => 2, '#3' => 3], $formatter->hospitalChoices());
        self::assertSame('#9', $formatter->hospital(9));
        self::assertSame('—', $formatter->hospital(null));
    }

    /**
     * @param array<int, string> $names
     */
    private function hospitalNames(array $names): HospitalNameRepository
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllKeyValue')->willReturn($names);

        return new HospitalNameRepository($connection);
    }

    /**
     * @param array<string, mixed> $metrics
     */
    private function jobRun(string $job, array $metrics): JobRun
    {
        $run = new JobRun('run-1', $job, 'console', 'succeeded', new \DateTimeImmutable('2026-10-25 12:00:00'));
        new \ReflectionProperty(JobRun::class, 'metrics')->setValue($run, $metrics);

        return $run;
    }
}
