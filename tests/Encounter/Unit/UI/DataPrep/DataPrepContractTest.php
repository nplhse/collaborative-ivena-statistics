<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\UI\DataPrep;

use App\Encounter\Contract\Schema\EncounterDimensionInterface;
use App\Encounter\Contract\Schema\EncounterField;
use App\Tests\Fixtures\Plugin\InpatientStay\InpatientStayDimension;
use Cois\Plugin\EdVisit\Application\Catalog\MtsCatalog;
use Cois\Plugin\EdVisit\Application\Dimension\EdVisitDimension;
use Cois\Plugin\EdVisit\Domain\Enum\CareLevel;
use Cois\Plugin\EdVisit\Domain\Enum\Disposition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the browser tool (#1051) in line with the server: the field contract it writes, the codes
 * of its bundled profiles and the fingerprint rule `ed-visit:v1`.
 */
final class DataPrepContractTest extends TestCase
{
    private const string SOURCE = __DIR__.'/../../../../../src/Encounter/UI/DataPrep';
    private const string FIXTURES = __DIR__.'/../../../DataPrep/fixtures';

    /**
     * @return iterable<string, array{string, EncounterDimensionInterface}>
     */
    public static function schemaFiles(): iterable
    {
        yield 'ed_visit' => [self::SOURCE.'/schema/ed_visit.v1.json', new EdVisitDimension()];
        yield 'inpatient_stay (test bundle)' => [__DIR__.'/../../../../Fixtures/Plugin/InpatientStay/data-prep/inpatient_stay.v1.json', new InpatientStayDimension()];
    }

    #[DataProvider('schemaFiles')]
    public function testToolSchemaMatchesItsDimension(string $file, EncounterDimensionInterface $dimension): void
    {
        $expected = array_map(static function (EncounterField $field): array {
            $entry = ['name' => $field->name, 'type' => $field->type->value];
            if ($field->required) {
                $entry['required'] = true;
            }
            if ([] !== $field->allowedValues) {
                $entry['values'] = $field->allowedValues;
            }
            if (null !== $field->min) {
                $entry['min'] = $field->min;
            }
            if (null !== $field->max) {
                $entry['max'] = $field->max;
            }

            return $entry;
        }, $dimension->fields(1));

        $schema = $this->json($file);

        self::assertSame($dimension->schema(), $schema['schema']);
        self::assertSame(1, $schema['version']);
        self::assertEquals($expected, $schema['fields'], 'Regenerate the schema file from its dimension.');
        self::assertArrayNotHasKey('keys', $schema, 'Key rules come from the dimension (#1301).');
    }

    #[DataProvider('profiles')]
    public function testProfileOnlyWritesCodesOfTheSchema(string $file): void
    {
        $profile = $this->json(self::SOURCE.'/profiles/'.$file);
        $fields = [];
        foreach (new EdVisitDimension()->fields(1) as $field) {
            $fields[$field->name] = $field;
        }

        self::assertContains($profile['vitalsReference'], $fields['vitals_reference']->allowedValues);
        foreach ($profile['fields'] as $name => $rule) {
            foreach ($rule['targets'] ?? [$name] as $target) {
                self::assertArrayHasKey($target, $fields, sprintf('Profile field "%s" is not part of ed_visit v1.', $target));
            }
            $allowed = $fields[$name]->allowedValues ?? [];
            if ('code' === $rule['type'] || 'codeSet' === $rule['type']) {
                $codes = array_merge(array_values($rule['map']), array_values($rule['prefixes'] ?? []));
                self::assertSame([], array_values(array_diff($codes, $allowed)), sprintf('Unknown codes for "%s".', $name));
            }
            if ('catalog' === $rule['type'] && isset($rule['catalog'])) {
                self::assertSame(MtsCatalog::ID, $rule['catalog'], 'The tool bundles the shared MTS catalog only.');
                $catalog = MtsCatalog::bundledData();
                self::assertIsList($catalog[$rule['list']] ?? null, sprintf('Catalog list "%s/%s" is missing.', $rule['catalog'], $rule['list']));
                self::assertContains($rule['unlisted'], ['empty', 'code']);
            }
            if ('disposition' === $rule['type']) {
                foreach ($rule['map'] as $target) {
                    $disposition = Disposition::from($target['disposition']);
                    if (isset($target['careLevel'])) {
                        CareLevel::from($target['careLevel']);
                        self::assertContains($disposition, [Disposition::Admitted, Disposition::TransferredExternal]);
                    }
                }
            }
        }
    }

    /**
     * The vectors the JavaScript tests check against follow the key rules of the dimension (#1301).
     */
    public function testFingerprintVectorsFollowTheDocumentedRule(): void
    {
        $data = $this->json(self::FIXTURES.'/fingerprint-vectors.json');
        $key = (string) hex2bin((string) $data['key']);

        $rules = new EdVisitDimension()->keyRules(1);

        self::assertSame(hash_hmac('sha256', 'encounter-key-check', $key), $data['keyCheck']);
        // The JS tests read the keys block from the vectors; it is what the renderer writes for ed_visit v1.
        self::assertSame($rules->toArray(), $data['keys']);
        self::assertNotEmpty($data['vectors']);
        foreach ($data['vectors'] as $vector) {
            $caseNumber = (string) preg_replace('/\D/', '', (string) $vector['caseNumber']);
            self::assertSame(1, preg_match('/^(\d{2})\.(\d{2})\.(\d{4})\s+(\d{2}):(\d{2})(?::(\d{2}))?$/', (string) $vector['anchor'], $m));
            $anchor = new \DateTimeImmutable(sprintf('%s-%s-%sT%s:%s:%s', $m[3], $m[2], $m[1], $m[4], $m[5], '' === ($m[6] ?? '') ? '00' : $m[6]));
            $input = $rules->fingerprintInput((string) $vector['caseNumber'], (string) $vector['anchorSource'], $anchor);

            self::assertSame($input, $vector['input']);
            self::assertSame(hash_hmac('sha256', $input, $key), $vector['visitFingerprint']);
            self::assertSame(hash_hmac('sha256', $rules->caseKeyInput((string) $vector['caseNumber']), $key), $vector['caseKey']);
            self::assertSame('case:v1|'.$caseNumber, $rules->caseKeyInput((string) $vector['caseNumber']));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function profiles(): iterable
    {
        foreach (glob(self::SOURCE.'/profiles/*.json') ?: [] as $path) {
            yield basename($path) => [basename($path)];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $path): array
    {
        /* @var array<string, mixed> */
        return json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
    }
}
