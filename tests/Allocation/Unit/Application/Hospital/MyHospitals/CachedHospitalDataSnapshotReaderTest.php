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
}
