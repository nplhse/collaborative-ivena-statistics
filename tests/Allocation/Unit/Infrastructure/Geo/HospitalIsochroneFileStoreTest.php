<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Infrastructure\Geo;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\State;
use App\Allocation\Infrastructure\Geo\HospitalIsochroneFileStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class HospitalIsochroneFileStoreTest extends TestCase
{
    private string $baseDirectory;

    private HospitalIsochroneFileStore $store;

    #[\Override]
    protected function setUp(): void
    {
        $this->baseDirectory = sys_get_temp_dir().'/hospital-isochrones-'.bin2hex(random_bytes(4));
        $this->store = new HospitalIsochroneFileStore($this->baseDirectory);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDirectory);
    }

    public function testFindReturnsNullWhenFileIsMissing(): void
    {
        self::assertNull($this->store->findForHospital($this->hospital()));
        self::assertFalse($this->store->existsForHospital($this->hospital()));
    }

    public function testWriteThenFindRoundTripsSlimGeoJson(): void
    {
        $hospital = $this->hospital();
        $geojson = [
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'properties' => ['value' => 600],
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[8.5, 50.0], [8.8, 50.0], [8.8, 50.3], [8.5, 50.3], [8.5, 50.0]]],
                    ],
                ],
            ],
        ];

        $this->store->writeForHospital($hospital, $geojson);

        self::assertTrue($this->store->existsForHospital($hospital));
        $path = $this->store->pathFor($hospital);
        self::assertNotNull($path);
        self::assertStringContainsString('/7/', $path);
        self::assertSame($geojson, $this->store->findForHospital($hospital));
    }

    public function testWriteThenFindPreservesOriginProperties(): void
    {
        $hospital = $this->hospital();
        $geojson = [
            'type' => 'FeatureCollection',
            'properties' => ['origin' => ['lat' => 50.1109, 'lng' => 8.6821]],
            'features' => [
                [
                    'type' => 'Feature',
                    'properties' => ['value' => 600],
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[8.5, 50.0], [8.8, 50.0], [8.8, 50.3], [8.5, 50.3], [8.5, 50.0]]],
                    ],
                ],
            ],
        ];

        $this->store->writeForHospital($hospital, $geojson);

        $found = $this->store->findForHospital($hospital);
        self::assertIsArray($found);
        self::assertSame($geojson['features'], $found['features']);
        self::assertSame(['lat' => 50.1109, 'lng' => 8.6821], $found['properties']['origin'] ?? null);
    }

    public function testFindReturnsNullForInvalidJson(): void
    {
        $hospital = $this->hospital();
        $path = $this->store->pathFor($hospital);
        self::assertNotNull($path);
        self::assertTrue(mkdir(\dirname($path), 0775, true));
        self::assertNotFalse(file_put_contents($path, '{not-json'));

        self::assertNull($this->store->findForHospital($hospital));
    }

    public function testPathIsNullWithoutStateIdOrPublicId(): void
    {
        $hospital = new Hospital()->setName('Klinik');

        self::assertNull($this->store->pathFor($hospital));
        self::assertNull($this->store->findForHospital($hospital));

        $withoutStateId = new Hospital()
            ->setName('Klinik')
            ->setState(new State()->setName('Hessen'))
            ->setPublicId(Uuid::v4());

        self::assertNull($this->store->pathFor($withoutStateId));
    }

    public function testWriteThrowsWhenHospitalCannotBePathed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Hospital is missing a public id or state id.');

        $this->store->writeForHospital(new Hospital()->setName('Klinik'), [
            'type' => 'FeatureCollection',
            'features' => [],
        ]);
    }

    public function testFindReturnsNullForEmptyFileOrEmptyFeatures(): void
    {
        $hospital = $this->hospital();
        $path = $this->store->pathFor($hospital);
        self::assertNotNull($path);
        self::assertTrue(mkdir(\dirname($path), 0775, true));
        self::assertNotFalse(file_put_contents($path, ''));
        self::assertNull($this->store->findForHospital($hospital));

        self::assertNotFalse(file_put_contents($path, json_encode([
            'type' => 'FeatureCollection',
            'features' => ['not-an-array'],
        ], JSON_THROW_ON_ERROR)));
        self::assertNull($this->store->findForHospital($hospital));
    }

    public function testFindReturnsNullWhenTypeIsNotFeatureCollection(): void
    {
        $hospital = $this->hospital();
        $path = $this->store->pathFor($hospital);
        self::assertNotNull($path);
        self::assertTrue(mkdir(\dirname($path), 0775, true));
        self::assertNotFalse(file_put_contents($path, json_encode(['type' => 'Feature', 'features' => []], JSON_THROW_ON_ERROR)));

        self::assertNull($this->store->findForHospital($hospital));
    }

    private function hospital(): Hospital
    {
        $state = new State()->setName('Hessen');
        new \ReflectionProperty(State::class, 'id')->setValue($state, 7);

        return new Hospital()
            ->setName('Uni-Klinik')
            ->setState($state)
            ->setPublicId(Uuid::v4());
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            if ($file->isDir()) {
                rmdir($file->getPathname());
                continue;
            }

            unlink($file->getPathname());
        }

        rmdir($directory);
    }
}
