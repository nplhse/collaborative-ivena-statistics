<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Infrastructure\Geo;

use App\Allocation\Application\Hospital\IsochroneOrigin;
use App\Allocation\Application\Hospital\PreparedLocation\PreparedLocation;
use App\Allocation\Infrastructure\Geo\PreparedLocationFileStore;
use PHPUnit\Framework\TestCase;

final class PreparedLocationFileStoreTest extends TestCase
{
    private string $baseDirectory;

    private PreparedLocationFileStore $store;

    #[\Override]
    protected function setUp(): void
    {
        $this->baseDirectory = sys_get_temp_dir().'/prepared-locations-'.bin2hex(random_bytes(4));
        $this->store = new PreparedLocationFileStore($this->baseDirectory);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (glob($this->baseDirectory.'/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->baseDirectory)) {
            rmdir($this->baseDirectory);
        }
    }

    public function testFindReturnsNullWhenMissing(): void
    {
        self::assertNull($this->store->find('musterstadt'));
        self::assertFalse($this->store->exists('musterstadt'));
        self::assertSame([], $this->store->all());
    }

    public function testSaveThenFindRoundTrips(): void
    {
        $location = $this->location('musterstadt');

        $this->store->save($location);

        self::assertTrue($this->store->exists('musterstadt'));
        self::assertFileExists($this->baseDirectory.'/musterstadt.json');
        self::assertEquals($location, $this->store->find('musterstadt'));
    }

    public function testAllIsSortedBySlugAndSkipsUnreadableFiles(): void
    {
        $this->store->save($this->location('zeta'));
        $this->store->save($this->location('alpha'));
        file_put_contents($this->baseDirectory.'/broken.json', '{not json');
        // A file whose name does not match the slug inside is ignored.
        file_put_contents($this->baseDirectory.'/renamed.json', (string) file_get_contents($this->baseDirectory.'/alpha.json'));

        $slugs = array_map(static fn (PreparedLocation $location): string => $location->slug, $this->store->all());

        self::assertSame(['alpha', 'zeta'], $slugs);
        self::assertNull($this->store->find('broken'));
        self::assertNull($this->store->find('renamed'));
    }

    public function testEmptyFileAndJsonThatIsNoObjectAreIgnored(): void
    {
        mkdir($this->baseDirectory);
        file_put_contents($this->baseDirectory.'/empty.json', '');
        file_put_contents($this->baseDirectory.'/number.json', '42');

        self::assertNull($this->store->find('empty'));
        self::assertNull($this->store->find('number'));
        self::assertSame([], $this->store->all());
    }

    public function testSaveFailsWhenTheDirectoryCannotBeCreated(): void
    {
        // A regular file where the directory should be.
        file_put_contents($this->baseDirectory, 'not a directory');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Unable to create prepared location directory');
            $this->withoutWarnings(fn () => $this->store->save($this->location('musterstadt')));
        } finally {
            unlink($this->baseDirectory);
        }
    }

    public function testSaveFailsWhenTheFileCannotBeWritten(): void
    {
        // A directory where the file should be.
        mkdir($this->baseDirectory.'/musterstadt.json', 0775, true);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Unable to write prepared location "musterstadt"');
            $this->withoutWarnings(fn () => $this->store->save($this->location('musterstadt')));
        } finally {
            rmdir($this->baseDirectory.'/musterstadt.json');
        }
    }

    public function testInvalidSlugIsNeverResolvedToAPath(): void
    {
        self::assertNull($this->store->find('../etc/passwd'));
        self::assertFalse($this->store->exists('../etc/passwd'));
    }

    /**
     * mkdir() and file_put_contents() warn before they return false; the store turns that into an exception.
     */
    private function withoutWarnings(\Closure $action): void
    {
        set_error_handler(static fn (): bool => true, \E_WARNING);
        try {
            $action();
        } finally {
            restore_error_handler();
        }
    }

    private function location(string $slug): PreparedLocation
    {
        return new PreparedLocation(
            slug: $slug,
            label: 'Musterstadt',
            address: null,
            latitude: 50.5,
            longitude: 8.25,
            isochrones: IsochroneOrigin::withCoordinates([
                'type' => 'FeatureCollection',
                'features' => [
                    ['type' => 'Feature', 'properties' => ['value' => 600], 'geometry' => ['type' => 'Polygon', 'coordinates' => []]],
                ],
            ], 50.5, 8.25),
            preparedAt: new \DateTimeImmutable('2026-10-28T12:00:00+00:00'),
        );
    }
}
