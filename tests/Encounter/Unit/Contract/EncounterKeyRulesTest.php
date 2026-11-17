<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Contract;

use App\Encounter\Contract\Schema\EncounterKeyRules;
use App\Tests\Fixtures\Plugin\InpatientStay\InpatientStayDimension;
use Cois\Plugin\EdVisit\Application\Dimension\EdVisitDimension;
use PHPUnit\Framework\TestCase;

/**
 * Key rules come from the dimension (#1301). Two schemas share the case key, so records of one case find the same
 * encounter; each schema keeps its own fingerprint rule. Key and case numbers of fingerprint-vectors.json.
 */
final class EncounterKeyRulesTest extends TestCase
{
    private const string KEY_HEX = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';

    public function testEdVisitAndInpatientStayShareTheCaseKeyForTheSameNormalisedCaseNumber(): void
    {
        $key = (string) hex2bin(self::KEY_HEX);
        $edVisit = new EdVisitDimension()->keyRules(1);
        $stay = new InpatientStayDimension()->keyRules(1);

        foreach (['00012345', '0001-2345'] as $caseNumber) {
            self::assertSame('case:v1|00012345', $edVisit->caseKeyInput($caseNumber));
            self::assertSame($edVisit->caseKeyInput($caseNumber), $stay->caseKeyInput($caseNumber));
            // The case key of the first two vectors.
            self::assertSame('2bf3f27f2fc648f1fa7a509494a18f4d38bb2408aa15015865c5466f610d1ded', hash_hmac('sha256', $stay->caseKeyInput($caseNumber), $key));
        }
    }

    public function testEachSchemaKeepsItsOwnFingerprintRule(): void
    {
        $anchor = new \DateTimeImmutable('2026-10-05T08:17:05');
        $edVisit = new EdVisitDimension()->keyRules(1);
        $stay = new InpatientStayDimension()->keyRules(1);

        self::assertSame('ed-visit:v1|00012345|assessment|2026-10-05T08:17:05', $edVisit->fingerprintInput('0001-2345', 'assessment', $anchor));
        self::assertSame('inpatient-stay:v1|00012345|2026-10-05T08:17:05', $stay->fingerprintInput('0001-2345', 'admission', $anchor));
        self::assertSame(['visit_fingerprint', 'stay_fingerprint'], [$edVisit->fingerprintField, $stay->fingerprintField]);
        self::assertContains($edVisit->fingerprintRule, new EdVisitDimension()->fingerprintRules(1));
        self::assertContains($stay->fingerprintRule, new InpatientStayDimension()->fingerprintRules(1));
    }

    public function testTheRenderedBlockIsWhatTheToolReads(): void
    {
        self::assertSame([
            'fingerprint' => ['field' => 'stay_fingerprint', 'rule' => 'inpatient-stay:v1', 'parts' => ['case_number', 'anchor']],
            'caseKey' => ['field' => 'case_key', 'prefix' => 'case:v1', 'parts' => ['case_number']],
        ], new InpatientStayDimension()->keyRules(1)->toArray());
    }

    public function testUnknownPartsAndMissingValuesAreRefused(): void
    {
        try {
            new EncounterKeyRules('f', 'r:v1', ['birth_date'], 'case_key', 'case:v1');
            self::fail('An unknown part is refused.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('birth_date', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        new EdVisitDimension()->keyRules(1)->fingerprintInput('1', 'assessment', null);
    }
}
