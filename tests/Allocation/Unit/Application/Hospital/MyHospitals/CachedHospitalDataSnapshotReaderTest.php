<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Hospital\MyHospitals;

use App\Allocation\Application\Hospital\MyHospitals\CachedHospitalDataSnapshotReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class CachedHospitalDataSnapshotReaderTest extends TestCase
{
    public function testReadsOnlyMissingHospitalsInOneCall(): void
    {
        $inner = new RecordingSnapshotReader();
        $reader = new CachedHospitalDataSnapshotReader($inner, new ArrayAdapter());

        $first = $reader->read([1, 2]);
        $second = $reader->read([2, 3, 1]);

        self::assertSame([[1, 2], [3]], $inner->calls);
        self::assertSame([1, 2], array_keys($first));
        self::assertSame([2, 3, 1], array_keys($second), 'Result keeps the requested order.');
        self::assertSame(2, $second[2]->hospitalId);
    }

    public function testEmptyRequestDoesNotRead(): void
    {
        $inner = new RecordingSnapshotReader();

        self::assertSame([], new CachedHospitalDataSnapshotReader($inner, new ArrayAdapter())->read([]));
        self::assertSame([], $inner->calls);
    }

    public function testRefreshOverwritesCachedEntries(): void
    {
        $inner = new RecordingSnapshotReader();
        $reader = new CachedHospitalDataSnapshotReader($inner, new ArrayAdapter());
        $reader->read([1, 2]);

        $reader->refresh([2, 2]);
        $reader->read([1, 2]);

        self::assertSame([[1, 2], [2]], $inner->calls, 'Refresh reads once; the next read is served from the cache.');
    }

    public function testRefreshDropsHospitalsTheReaderNoLongerKnows(): void
    {
        $cache = new ArrayAdapter();
        new CachedHospitalDataSnapshotReader(new RecordingSnapshotReader(), $cache)->read([1]);

        new CachedHospitalDataSnapshotReader(new ForgettingSnapshotReader(), $cache)->refresh([1]);

        self::assertFalse($cache->hasItem('hospital_data_snapshot.v1.1'));
    }

    public function testForgottenHospitalsAreReadAgain(): void
    {
        $inner = new RecordingSnapshotReader();
        $reader = new CachedHospitalDataSnapshotReader($inner, new ArrayAdapter());
        $reader->read([1, 2]);

        $reader->forget([1]);
        $reader->read([1, 2]);

        self::assertSame([[1, 2], [1]], $inner->calls);
    }
}
