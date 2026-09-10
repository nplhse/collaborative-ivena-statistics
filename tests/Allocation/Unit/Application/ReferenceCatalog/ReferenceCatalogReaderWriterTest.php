<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\ReferenceCatalog;

use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogDocument;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogReader;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogSyncResult;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogWriter;
use PHPUnit\Framework\TestCase;

final class ReferenceCatalogReaderWriterTest extends TestCase
{
    public function testLoadDefaultReadsCommittedCatalog(): void
    {
        $reader = new ReferenceCatalogReader(\dirname(__DIR__, 5));
        $document = $reader->loadDefault();

        self::assertContains('Hessen', $document->states);
        self::assertContains('Niedersachsen', $document->states);
        self::assertNotEmpty($document->dispatchAreas);
        self::assertSame($document, $reader->loadDefault());
    }

    public function testLoadMissingFileThrows(): void
    {
        $reader = new ReferenceCatalogReader(\dirname(__DIR__, 5));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');
        $reader->load(sys_get_temp_dir().'/missing-catalog-'.bin2hex(random_bytes(4)).'.yaml');
    }

    public function testLoadNonMappingYamlThrows(): void
    {
        $path = sys_get_temp_dir().'/catalog-scalar-'.bin2hex(random_bytes(4)).'.yaml';
        file_put_contents($path, "just a string\n");
        $reader = new ReferenceCatalogReader(\dirname(__DIR__, 5));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a YAML mapping');
        $reader->load($path);
    }

    public function testDefaultPathPointsAtCommittedCatalog(): void
    {
        $projectDir = \dirname(__DIR__, 5);
        $reader = new ReferenceCatalogReader($projectDir);

        self::assertSame($projectDir.'/fixtures/reference/catalog.yaml', $reader->defaultPath());
        self::assertFileExists($reader->defaultPath());
    }

    public function testWriterRoundTripPreservesNullDispatchAreaState(): void
    {
        $document = ReferenceCatalogDocument::fromArray([
            'states' => ['Hessen'],
            'dispatch_areas' => [
                ['name' => 'Frankfurt', 'state' => 'Hessen'],
                ['name' => 'Göttingen', 'state' => null],
            ],
            'departments' => ['Kardiologie'],
        ]);
        $path = sys_get_temp_dir().'/catalog-roundtrip-'.bin2hex(random_bytes(4)).'.yaml';
        $writer = new ReferenceCatalogWriter();
        $writer->write($path, $document);

        $loaded = new ReferenceCatalogReader(\dirname(__DIR__, 5))->load($path);
        self::assertSame(['Hessen'], $loaded->states);
        self::assertSame('Frankfurt', $loaded->dispatchAreas[0]['name']);
        self::assertSame('Hessen', $loaded->dispatchAreas[0]['state']);
        self::assertSame('Göttingen', $loaded->dispatchAreas[1]['name']);
        self::assertNull($loaded->dispatchAreas[1]['state']);
        self::assertSame(['Kardiologie'], $loaded->departments);
        self::assertStringContainsString('reference-catalog-yaml.md', (string) file_get_contents($path));
    }

    public function testWithTypesAndYamlKeys(): void
    {
        $document = ReferenceCatalogDocument::fromArray([
            'states' => ['Hessen'],
            'departments' => ['Kardiologie'],
            'hospitals' => [[
                'name' => 'KH',
                'state' => 'Hessen',
                'area' => 'Frankfurt',
                'size' => 'Small',
                'beds' => 10,
                'location' => 'Urban',
            ]],
        ]);
        $filtered = $document->withTypes([ReferenceCatalogType::State, ReferenceCatalogType::Department]);

        self::assertSame(['Hessen'], $filtered->states);
        self::assertSame(['Kardiologie'], $filtered->departments);
        self::assertSame([], $filtered->hospitals);
        self::assertSame('dispatch_areas', ReferenceCatalogType::DispatchArea->yamlKey());
        self::assertSame(['Kardiologie'], $document->names('departments.yaml'));
    }

    public function testSyncResultTableRowsAndHasChanges(): void
    {
        $empty = new ReferenceCatalogSyncResult();
        self::assertFalse($empty->hasChanges());

        $result = new ReferenceCatalogSyncResult(
            createdByType: ['state' => 2],
            skippedByType: ['dispatch-area' => 1],
            updatedByType: ['indication-group' => 1],
        );
        self::assertTrue($result->hasChanges());
        self::assertSame(2, $result->created());
        self::assertSame(1, $result->skipped());
        self::assertSame(1, $result->updated());
        self::assertSame([
            ['dispatch-area', '0', '1', '0'],
            ['indication-group', '0', '0', '1'],
            ['state', '2', '0', '0'],
        ], $result->tableRows());
    }
}
