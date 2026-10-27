<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\ReferenceCatalog;

use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogDocument;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogReader;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType;
use PHPUnit\Framework\TestCase;

final class ReferenceCatalogReaderTest extends TestCase
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
        self::assertSame(['Kardiologie'], $filtered->names('departments'));
        self::assertSame([], $filtered->hospitals);
        self::assertSame('dispatch_areas', ReferenceCatalogType::DispatchArea->yamlKey());
        self::assertSame(['Kardiologie'], $document->names('departments.yaml'));
    }
}
