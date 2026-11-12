<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Encounter;

use App\DataFixtures\Encounter\Application\EdVisitExport;
use App\DataFixtures\Encounter\Application\GeneratedEncounters;
use App\DataFixtures\Encounter\Application\SyntheticEncounterExports;
use App\DataFixtures\Encounter\Application\SyntheticEncounterKey;
use App\DataFixtures\Encounter\Application\SyntheticEncounterTruth;
use App\DataFixtures\Encounter\Application\SyntheticVisit;
use App\DataFixtures\Encounter\Application\SyntheticVisitKind;
use App\Encounter\Application\Dimension\EdVisitDimension;
use App\Encounter\Application\Dimension\EncounterField;
use PHPUnit\Framework\TestCase;

/**
 * Keys, exports and truth of synthetic encounter files (#1052), with five hand-made visits.
 */
final class SyntheticEncounterFilesTest extends TestCase
{
    private const string VECTORS = __DIR__.'/../../Encounter/DataPrep/fixtures/fingerprint-vectors.json';

    public function testKeyFollowsTheFingerprintRuleOfTheTool(): void
    {
        /** @var array{key: string, keyCheck: string, vectors: list<array{caseNumber: string, input: string, visitFingerprint: string, caseKey: string}>} $data */
        $data = json_decode((string) file_get_contents(self::VECTORS), true, flags: \JSON_THROW_ON_ERROR);
        $key = SyntheticEncounterKey::fromHex($data['key']);

        self::assertSame($data['keyCheck'], $key->checkValue());
        foreach ($data['vectors'] as $vector) {
            [, , $source, $anchor] = explode('|', $vector['input']);
            self::assertSame($vector['visitFingerprint'], $key->visitFingerprint($vector['caseNumber'], $source, $anchor), $vector['input']);
            self::assertSame($vector['caseKey'], $key->caseKey($vector['caseNumber']));
        }

        self::assertSame(
            $key->visitFingerprint('42', 'admission', '2026-10-06T23:59:00'),
            $key->keysOf('42', ['ed_admission_at' => '2026-10-06T23:59', 'first_assessment_contact_at' => ''])['visit_fingerprint'],
            'Minute precision gets the seconds :00.',
        );
    }

    public function testKeyIsFixedPerSeedAndHospitalAndReadableByTheTool(): void
    {
        $key = SyntheticEncounterKey::forHospital(740, 'a');

        self::assertSame($key->hex(), SyntheticEncounterKey::forHospital(740, 'a')->hex());
        self::assertNotSame($key->hex(), SyntheticEncounterKey::forHospital(740, 'b')->hex());
        self::assertNotSame($key->hex(), SyntheticEncounterKey::forHospital(741, 'a')->hex());
        self::assertSame(
            ['format' => 'encounter-key', 'version' => 1, 'label' => 'Test', 'created' => '2026-11-12', 'key' => $key->hex(), 'check' => $key->checkValue()],
            $key->keyFile('Test', '2026-11-12'),
        );
    }

    public function testOverlappingExportsRepeatDays11To20AndDocumentLateAssessmentsLater(): void
    {
        $generated = new GeneratedEncounters([
            $this->visit('1001', '2026-10-05T08:00'),
            $this->visit('1002', '2026-10-12T09:00'),
            $this->visit('1003', '2026-10-15T10:00', late: true),
            $this->visit('1004', '2026-10-20T23:50'),
            $this->visit('1005', '2026-10-28T11:00', late: true),
        ], []);

        [$first, $second] = SyntheticEncounterExports::overlapping($generated, new \DateTimeImmutable('2026-10-01'));

        self::assertSame('2026-10_part1', $first->name);
        self::assertSame(['1001', '1002', '1003', '1004'], $this->caseNumbers($first));
        self::assertSame(['1002', '1003', '1004', '1005'], $this->caseNumbers($second));
        // 1003 is first exported without assessment, then with it; 1005 appears for the first time in part 2.
        self::assertSame('', $first->rows[2]['cells']['first_assessment_contact_at']);
        self::assertSame('2026-10-15T10:07', $second->rows[1]['cells']['first_assessment_contact_at']);
        self::assertSame('', $second->rows[3]['cells']['first_assessment_contact_at']);
    }

    public function testExportFileHasManifestHeaderAndKeyedRows(): void
    {
        $key = SyntheticEncounterKey::forHospital(740, 'a');
        $export = new EdVisitExport('x', [['visit' => $this->visit('1001', '2026-10-05T08:00'), 'cells' => $this->visit('1001', '2026-10-05T08:00')->cells]]);

        $lines = explode("\n", $export->content($key, '1.0.2'));

        self::assertSame(sprintf('#encounter-exchange;schema=ed_visit;schema_version=1;profile=synthetic;profile_version=1;tool=1.0.2;key_check=%s;fingerprint=ed-visit:v1;rows=1', $key->checkValue()), $lines[0]);
        $header = array_map(static fn (EncounterField $field): string => $field->name, new EdVisitDimension()->fields(1));
        self::assertSame(implode(';', $header), $lines[1]);
        $row = array_combine($header, explode(';', $lines[2]));
        self::assertSame($key->visitFingerprint('1001', 'assessment', '2026-10-05T08:07:00'), $row['visit_fingerprint']);
        self::assertSame($key->caseKey('1001'), $row['case_key']);
        self::assertSame('2026-10-05T08:00', $row['ed_admission_at']);
        self::assertSame('', $lines[3], 'Final newline.');
        self::assertCount(4, $lines);
    }

    public function testTruthListsBothFingerprintsOfALateAssessment(): void
    {
        $key = SyntheticEncounterKey::forHospital(740, 'a');
        $truth = SyntheticEncounterTruth::of(new GeneratedEncounters([$this->visit('1003', '2026-10-15T10:00', late: true)], [7]), $key);

        self::assertSame([
            $key->visitFingerprint('1003', 'admission', '2026-10-15T10:00'),
            $key->visitFingerprint('1003', 'assessment', '2026-10-15T10:07'),
        ], $truth['visits'][0]['visit_fingerprints']);
        self::assertSame('self_presenter', $truth['visits'][0]['kind']);
        self::assertSame([7], $truth['allocations_without_visit']);
    }

    private function visit(string $caseNumber, string $admission, bool $late = false): SyntheticVisit
    {
        $at = new \DateTimeImmutable($admission);

        return new SyntheticVisit($caseNumber, SyntheticVisitKind::SelfPresenter, [
            'age' => '40',
            'sex' => 'F',
            'arrival_mode' => 'self',
            'ed_admission_at' => $at->format(SyntheticVisit::TIMESTAMP_FORMAT),
            'first_assessment_contact_at' => $at->modify('+7 minutes')->format(SyntheticVisit::TIMESTAMP_FORMAT),
            'mts_category' => 'green',
        ], lateAssessment: $late);
    }

    /**
     * @return list<string>
     */
    private function caseNumbers(EdVisitExport $export): array
    {
        return array_map(static fn (SyntheticVisit $visit): string => $visit->caseNumber, $export->visits());
    }
}
