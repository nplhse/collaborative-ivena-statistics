<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Application\ReferenceCatalog;

use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogChecksum;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackageReader;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogReadResult;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogViolation;
use App\Tests\Support\ReferenceCatalog\ReferenceCatalogPackageData as Data;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

final class ReferenceCatalogPackageReaderTest extends KernelTestCase
{
    public function testReadsAValidCompletePackage(): void
    {
        $result = $this->read($this->sealed(Data::complete()));

        self::assertSame([], $result->violations);
        self::assertTrue($result->isValid());
        self::assertSame(2, $result->package->counts()['indication-raw']);
        self::assertNull($result->package->indicationsRaw[1]->target);
        self::assertSame([Data::NORMALIZED], $result->package->indicationGroups[0]->members);
        self::assertSame('faulty_catalog', $result->package->departments[0]->aliases[0]->classification);
        self::assertSame(50.11, $result->package->hospitals[0]->coordinates?->lat);
    }

    public function testChecksumDoesNotDependOnLayout(): void
    {
        $data = $this->sealed(Data::complete());

        $compact = $this->reader()->readJson(json_encode($data, \JSON_THROW_ON_ERROR));
        $pretty = $this->reader()->readJson(json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));

        self::assertTrue($compact->isValid());
        self::assertTrue($pretty->isValid());
    }

    public function testRejectsInvalidJson(): void
    {
        $result = $this->reader()->readJson('{"meta": ');

        self::assertFalse($result->isValid());
        self::assertStringStartsWith('Invalid JSON', $result->violations[0]->message);
    }

    public function testRejectsAnotherVersionBeforeAnyOtherCheck(): void
    {
        $data = Data::complete();
        $data['meta']['version'] = 2;
        $data['hospitals'][0]['beds'] = 'many';
        $data['unknown'] = true;

        $result = $this->read($data);

        self::assertCount(1, $result->violations);
        self::assertSame('meta', $result->violations[0]->path);
        self::assertStringContainsString('expected format "ivena-reference-catalog" version 1, found format "ivena-reference-catalog" version 2', $result->violations[0]->message);
    }

    public function testRejectsAnotherFormat(): void
    {
        $data = Data::complete();
        $data['meta']['format'] = 'something-else';

        $result = $this->read($data);

        self::assertCount(1, $result->violations);
        self::assertStringContainsString('found format "something-else"', $result->violations[0]->message);
    }

    public function testCollectsTypeErrors(): void
    {
        $data = Data::complete();
        $data['hospitals'][0]['beds'] = 'many';
        $data['states'][0]['name'] = 5;

        $paths = $this->paths($this->read($data));

        self::assertContains('hospitals[0].beds', $paths);
        self::assertContains('states[0].name', $paths);
    }

    public function testRejectsUnknownFields(): void
    {
        $data = Data::complete();
        $data['hospitals'][0]['owner'] = 'someone';

        $result = $this->read($data);

        self::assertFalse($result->isValid());
        self::assertStringContainsString('owner', $result->violations[0]->message);
    }

    public function testReportsFieldRulesWithFilePaths(): void
    {
        $data = Data::complete();
        $data['states'][0]['id'] = 'not-a-uuid';
        $data['hospitals'][0]['size'] = 'Huge';
        $data['hospitals'][0]['address']['postal_code'] = '';
        $data['departments'][0]['aliases'][0]['classification'] = 'guess';
        $data['indications_normalized'][0]['code'] = '14';
        $data['indications_raw'][0]['review']['status'] = 'done';

        $paths = $this->paths($this->read($data));

        self::assertContains('states[0].id', $paths);
        self::assertContains('hospitals[0].size', $paths);
        self::assertContains('hospitals[0].address.postal_code', $paths);
        self::assertContains('departments[0].aliases[0].classification', $paths);
        self::assertContains('indications_normalized[0].code', $paths);
        self::assertContains('indications_raw[0].review.status', $paths);
    }

    public function testReportsDuplicateIdsAndNaturalKeys(): void
    {
        $data = Data::complete();
        $data['infections'][] = ['id' => Data::HOSPITAL, 'name' => 'mrsa'];
        $data['hospitals'][] = ['id' => Data::HOSPITAL] + $data['hospitals'][0];
        $data = Data::withCounts($data);

        $result = $this->read($this->sealed($data));
        $paths = $this->paths($result);

        self::assertContains('infections[1]', $paths, 'same name ignoring case');
        self::assertContains('hospitals[1].id', $paths);
        self::assertContains('hospitals[1]', $paths);
        self::assertNotContains('infections[1].id', $paths, 'ids only need to be unique within a section');
    }

    public function testReportsUnresolvedAndContradictoryReferences(): void
    {
        $unknown = '0192d6a1-0000-7000-8000-0000000000ff';
        $data = Data::complete();
        $data['dispatch_areas'][0]['state'] = $unknown;
        $data['indications_raw'][0]['target'] = $unknown;
        $data['indication_groups'][0]['members'] = [$unknown];
        $data['hospitals'][0]['area'] = Data::OTHER_AREA;

        $result = $this->read($this->sealed($data));
        $messages = $this->messagesByPath($result);

        self::assertArrayHasKey('dispatch_areas[0].state', $messages);
        self::assertArrayHasKey('indications_raw[0].target', $messages);
        self::assertArrayHasKey('indication_groups[0].members[0]', $messages);
        self::assertSame('Dispatch area belongs to another state than the hospital.', $messages['hospitals[0].area']);
    }

    public function testReportsAliasConflicts(): void
    {
        $data = Data::complete();
        $data['departments'][] = [
            'id' => '0192d6a1-0000-7000-8000-000000000022',
            'name' => 'Kardiologie',
            'aliases' => [
                ['name' => 'allgemein innere medizin', 'classification' => 'historical', 'source' => 'test'],
                ['name' => 'Kardiologie', 'classification' => 'historical', 'source' => 'test'],
                ['name' => 'Allgemeine Innere Medizin', 'classification' => 'historical', 'source' => 'test'],
            ],
        ];
        $data = Data::withCounts($data);

        $messages = $this->messagesByPath($this->read($this->sealed($data)));

        self::assertStringContainsString('already mapped', $messages['departments[1].aliases[0].name']);
        self::assertStringContainsString('repeats its canonical name', $messages['departments[1].aliases[1].name']);
        self::assertStringContainsString('collides with canonical name', $messages['departments[1].aliases[2].name']);
    }

    public function testReportsCountsTypesAndCompleteness(): void
    {
        $data = Data::complete();
        $data['meta']['counts']['hospital'] = 7;
        $data['meta']['types'] = array_values(array_diff($data['meta']['types'], ['infection']));
        unset($data['meta']['counts']['infection']);

        $messages = $this->messagesByPath($this->read($this->sealed($data)));

        self::assertArrayHasKey('infections', $messages);
        self::assertArrayHasKey('meta.counts', $messages);
        self::assertArrayHasKey('meta.complete', $messages);
    }

    public function testReportsAChangedFileThroughTheChecksum(): void
    {
        $data = $this->sealed(Data::complete());
        $data['hospitals'][0]['beds'] = 801;

        $messages = $this->messagesByPath($this->read($data));

        self::assertSame(['meta.checksum.value'], array_keys($messages));
    }

    public function testPartialPackageLeavesReferencesToMissingTypesToTheImport(): void
    {
        $full = Data::complete();
        $data = [
            'meta' => ['types' => ['hospital'], 'complete' => false] + $full['meta'],
            'hospitals' => $full['hospitals'],
        ];
        $data = Data::withCounts($data);

        $result = $this->read($this->sealed($data));

        self::assertSame([], $result->violations);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function sealed(array $data): array
    {
        self::bootKernel();

        /** @var DenormalizerInterface $denormalizer */
        $denormalizer = self::getContainer()->get(DenormalizerInterface::class);
        /** @var ReferenceCatalogChecksum $checksum */
        $checksum = self::getContainer()->get(ReferenceCatalogChecksum::class);

        return Data::withChecksum($data, $denormalizer, $checksum);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function read(array $data): ReferenceCatalogReadResult
    {
        return $this->reader()->readJson(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    private function reader(): ReferenceCatalogPackageReader
    {
        self::bootKernel();

        /** @var ReferenceCatalogPackageReader $reader */
        $reader = self::getContainer()->get(ReferenceCatalogPackageReader::class);

        return $reader;
    }

    /**
     * @return list<string>
     */
    private function paths(ReferenceCatalogReadResult $result): array
    {
        return array_map(static fn (ReferenceCatalogViolation $violation): string => $violation->path, $result->violations);
    }

    /**
     * @return array<string, string>
     */
    private function messagesByPath(ReferenceCatalogReadResult $result): array
    {
        $messages = [];
        foreach ($result->violations as $violation) {
            $messages[$violation->path] = $violation->message;
        }

        return $messages;
    }
}
