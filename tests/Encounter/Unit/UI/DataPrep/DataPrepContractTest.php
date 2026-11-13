<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\UI\DataPrep;

use App\Encounter\Application\Catalog\MtsCatalog;
use App\Encounter\Application\Dimension\EdVisitDimension;
use App\Encounter\Application\Dimension\EncounterField;
use App\Encounter\Domain\Enum\CareLevel;
use App\Encounter\Domain\Enum\Disposition;
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

    public function testToolSchemaMatchesTheEdVisitDimension(): void
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
        }, new EdVisitDimension()->fields(1));

        $schema = $this->json(self::SOURCE.'/schema/ed_visit.v1.json');

        self::assertSame(EdVisitDimension::SCHEMA, $schema['schema']);
        self::assertSame(1, $schema['version']);
        self::assertEquals($expected, $schema['fields'], 'Regenerate schema/ed_visit.v1.json from EdVisitDimension.');
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
     * Independent PHP computation of the vectors the JavaScript tests check against.
     */
    public function testFingerprintVectorsFollowTheDocumentedRule(): void
    {
        $data = $this->json(self::FIXTURES.'/fingerprint-vectors.json');
        $key = (string) hex2bin((string) $data['key']);

        self::assertSame(hash_hmac('sha256', 'encounter-key-check', $key), $data['keyCheck']);
        self::assertNotEmpty($data['vectors']);
        foreach ($data['vectors'] as $vector) {
            $caseNumber = (string) preg_replace('/\D/', '', (string) $vector['caseNumber']);
            self::assertSame(1, preg_match('/^(\d{2})\.(\d{2})\.(\d{4})\s+(\d{2}):(\d{2})(?::(\d{2}))?$/', (string) $vector['anchor'], $m));
            $anchor = sprintf('%s-%s-%sT%s:%s:%s', $m[3], $m[2], $m[1], $m[4], $m[5], $m[6] ?? '00');
            $input = 'ed-visit:v1|'.$caseNumber.'|'.$vector['anchorSource'].'|'.$anchor;

            self::assertSame($input, $vector['input']);
            self::assertSame(hash_hmac('sha256', $input, $key), $vector['visitFingerprint']);
            self::assertSame(hash_hmac('sha256', 'case:v1|'.$caseNumber, $key), $vector['caseKey']);
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
