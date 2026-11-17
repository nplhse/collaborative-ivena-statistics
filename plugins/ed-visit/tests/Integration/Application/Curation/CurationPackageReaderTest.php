<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Tests\Integration\Application\Curation;

use App\Tests\Support\Encounter\CurationPackageData;
use Cois\Plugin\EdVisit\Application\Curation\Package\CurationPackageReader;
use Cois\Plugin\EdVisit\Application\Curation\Package\CurationViolation;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Validation of an encounter curation package before anything is written (#1198): format and version first, then
 * structure, field rules and package rules. No database.
 */
final class CurationPackageReaderTest extends KernelTestCase
{
    private CurationPackageReader $reader;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->reader = self::getContainer()->get(CurationPackageReader::class);
    }

    public function testSealedPackageIsValidWhateverItsLayout(): void
    {
        $data = CurationPackageData::newInstance();

        self::assertTrue($this->reader->readJson(CurationPackageData::json($data))->isValid());
        self::assertTrue($this->reader->readJson(json_encode($data, \JSON_THROW_ON_ERROR))->isValid(), 'escaped, one line: same checksum');
    }

    public function testAnotherVersionIsRejectedBeforeAnyOtherCheck(): void
    {
        $data = CurationPackageData::newInstance();
        $data['meta']['version'] = 2;
        $data['diagrams'][0]['code'] = 'Not A Code';

        self::assertSame(
            ['meta: Unsupported package: expected format "ivena-encounter-curation" version 1, found format "ivena-encounter-curation" version 2. Export the package again with this application version.'],
            $this->problems($data),
        );
    }

    public function testChangedContentFailsTheChecksum(): void
    {
        $data = CurationPackageData::newInstance();
        $data['diagrams'][1]['german_name'] = 'Augenprobleme (Kinder)';

        self::assertSame(['meta.checksum.value: Checksum does not match the content. The file was changed or truncated after export.'], $this->problems($data));
    }

    public function testUnknownFieldIsRejected(): void
    {
        $data = CurationPackageData::newInstance();
        $data['diagrams'][1]['status'] = 'curated';

        self::assertSame([': Unknown field(s): diagrams[1].status'], $this->problems(CurationPackageData::seal($data)));
    }

    public function testFieldRules(): void
    {
        $data = CurationPackageData::newInstance();
        $data['problem_areas'][0]['diagrams'][0]['points'] = 4;
        $data['problem_areas'][0]['pzc'] = [990, 1000];
        $data['diagrams'][2]['source'] = 'first_seen_somewhere';

        $problems = $this->problems(CurationPackageData::seal($data));

        self::assertCount(3, $problems);
        self::assertStringStartsWith('diagrams[2].source:', $problems[0]);
        self::assertStringStartsWith('problem_areas[0].pzc[1]:', $problems[1]);
        self::assertStringStartsWith('problem_areas[0].diagrams[0].points:', $problems[2]);
    }

    public function testPackageRulesForCodesAndReferences(): void
    {
        $data = CurationPackageData::newInstance();
        // An alias with the code of an entry, an alias to a missing entry, an assignment and a rule naming missing
        // entries, and a PZC listed in two areas.
        $data['aliases'][] = ['kind' => 'diagram', 'code' => 'allergie', 'label' => 'Allergie', 'target_code' => 'hautprobleme'];
        $data['aliases'][] = ['kind' => 'indicator', 'code' => 'jucken', 'label' => 'Jucken', 'target_code' => 'pruritus'];
        $data['assignments'][] = ['diagram' => 'hautprobleme', 'indicator' => 'ausschlag'];
        $data['problem_areas'][] = CurationPackageData::area('augen', 'Augen', null, [991], [['code' => 'sehstoerung', 'points' => 2]]);

        self::assertSame([
            'aliases[2].code: Code "allergie" is also the code of an entry of kind diagram.',
            'aliases[3].target_code: The target "pruritus" is not an entry of kind indicator in this package.',
            'assignments[1].indicator: The indicator "ausschlag" is not in this package.',
            'problem_areas[1].pzc[0]: PZC 991 also belongs to problem_areas[0]; a PZC belongs to at most one area.',
            'problem_areas[1].diagrams[0].code: The diagram "sehstoerung" is not in this package.',
        ], $this->problems(CurationPackageData::seal($data)));
    }

    public function testMoreDuplicatesAndAnotherChecksumAlgorithm(): void
    {
        $data = CurationPackageData::newInstance();
        $data['diagrams'][] = $data['diagrams'][1];
        $data['aliases'][] = $data['aliases'][0];
        $data['assignments'][] = ['diagram' => 'sehstoerung', 'indicator' => 'juckreiz'];
        $data['assignments'][] = $data['assignments'][0];
        $data['problem_areas'][] = array_merge($data['problem_areas'][0], ['pzc' => []]);
        $data['problem_areas'][0]['diagrams'][] = ['code' => 'hautprobleme', 'points' => 1];
        $data = CurationPackageData::seal($data);
        $data['meta']['counts']['hospitals'] = 0;

        self::assertSame([
            'meta.counts: Unknown section "hospitals" in meta.counts.',
            'diagrams[3].code: Code "augenprobleme_bei_kindern" is already used by diagrams[1].',
            'aliases[2].code: Alias code "augenprobleme_kinder" is already used by aliases[0].',
            'assignments[1].diagram: The diagram "sehstoerung" is not in this package.',
            'assignments[2]: Same assignment as assignments[0].',
            'problem_areas[0].diagrams[1].code: The diagram "hautprobleme" already has a rule in this area.',
            'problem_areas[1].code: Area code "haut_und_augen" is already used by problem_areas[0].',
        ], $this->problems($data));

        $data = CurationPackageData::newInstance();
        $data['meta']['checksum']['algorithm'] = 'md5';
        self::assertSame(['meta.checksum.algorithm: Expected the checksum algorithm "sha256".'], $this->problems($data));
    }

    public function testFileAndStructureProblems(): void
    {
        self::assertStringStartsWith('File not found or not readable:', $this->reader->read('/nonexistent/curation.json')->violations[0]->message);
        self::assertStringStartsWith('Invalid JSON:', $this->reader->readJson('{"meta":')->violations[0]->message);
        self::assertSame('A package must be a JSON object.', $this->reader->readJson('42')->violations[0]->message);

        $data = CurationPackageData::newInstance();
        $data['problem_areas'][0]['diagrams'][0]['points'] = 'three';
        $data['diagrams'][0]['english_name'] = 42;
        self::assertSame([
            'diagrams[0].english_name: Expected null or string, found int.',
            'problem_areas[0].diagrams[0].points: Expected int, found string.',
        ], $this->problems(CurationPackageData::seal($data)));

        $data = CurationPackageData::newInstance();
        $data['extra'] = true;
        self::assertSame([': Unknown field(s): extra'], $this->problems(CurationPackageData::seal($data)));
    }

    public function testCountsMustMatchTheSections(): void
    {
        $data = CurationPackageData::newInstance();
        $data['meta']['counts']['diagrams'] = 2;

        self::assertSame(['meta.counts: Count for "diagrams" is 2, but the section has 3 entries.'], $this->problems($data));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function problems(array $data): array
    {
        return array_map(
            static fn (CurationViolation $violation): string => \sprintf('%s: %s', $violation->path, $violation->message),
            $this->reader->readJson(CurationPackageData::json($data))->violations,
        );
    }
}
