<?php

declare(strict_types=1);

namespace App\Encounter\Application\Import;

use App\Encounter\Application\Dimension\EncounterDimensionRegistry;
use App\Encounter\Application\Dimension\EncounterField;
use App\Encounter\Application\Dimension\EncounterFieldType;
use App\Encounter\Domain\Enum\EncounterFileRejection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * File-level checks of a canonical exchange file; any finding rejects the whole file (ADR 026, #1048).
 *
 * Order matters: the checks for identifying content run first and on every row, so a file with direct
 * identifiers is recognised (and deleted by the caller) even when its manifest or header is also wrong.
 *
 * 1. header columns naming identifying source data → raw export
 * 2. identifying cell content → identifying content
 * 3. manifest format, schema and version, profile, minimum tool version, fingerprint rule
 * 4. exact header of the schema version, row count of the manifest
 *
 * The key check value is checked against the hospital's key state when the run writes (ADR 027).
 */
final readonly class EncounterFileValidator
{
    private const string PROFILE_PATTERN = '/^[a-z0-9][a-z0-9-]{0,31}$/';
    private const string PROFILE_VERSION_PATTERN = '/^[1-9]\d{0,3}$/';
    private const string TOOL_VERSION_PATTERN = '/^\d{1,4}\.\d{1,4}\.\d{1,4}$/';

    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private EncounterDimensionRegistry $dimensions,
        private IdentifyingContentDetector $contentDetector,
        #[Autowire(param: 'app.encounter.data_prep.min_version')]
        private string $minToolVersion,
    ) {
    }

    /**
     * @param string $schema schema chosen at upload
     *
     * @return array{0: EncounterManifest, 1: list<EncounterField>} manifest and the field contract of its version
     *
     * @throws EncounterFileRejectedException
     */
    public function validate(EncounterFile $file, string $schema): array
    {
        $knownFields = $this->knownFields($schema);
        $this->assertNoIdentifyingColumns($file->header, $knownFields);
        $this->assertNoIdentifyingContent($file, $knownFields);

        $manifest = EncounterManifest::parse($file->manifestLine);
        if ($manifest->schema !== $schema || !$this->dimensions->has($schema)) {
            throw new EncounterFileRejectedException(EncounterFileRejection::SchemaUnsupported);
        }

        $dimension = $this->dimensions->get($schema);
        if (!\in_array($manifest->schemaVersion, $dimension->versions(), true)) {
            throw new EncounterFileRejectedException(EncounterFileRejection::SchemaUnsupported);
        }

        if (1 !== preg_match(self::PROFILE_PATTERN, $manifest->profile) || 1 !== preg_match(self::PROFILE_VERSION_PATTERN, $manifest->profileVersion)) {
            throw new EncounterFileRejectedException(EncounterFileRejection::ProfileUnsupported);
        }

        if (1 !== preg_match(self::TOOL_VERSION_PATTERN, $manifest->toolVersion)) {
            throw new EncounterFileRejectedException(EncounterFileRejection::ManifestInvalid);
        }

        if (version_compare($manifest->toolVersion, $this->minToolVersion, '<')) {
            throw new EncounterFileRejectedException(EncounterFileRejection::ToolVersionTooOld);
        }

        if (!\in_array($manifest->fingerprintRule, $dimension->fingerprintRules($manifest->schemaVersion), true)) {
            throw new EncounterFileRejectedException(EncounterFileRejection::FingerprintRuleUnsupported);
        }

        $fields = $dimension->fields($manifest->schemaVersion);
        $expectedHeader = array_map(static fn (EncounterField $field): string => $field->name, $fields);
        if ($file->header !== $expectedHeader) {
            throw new EncounterFileRejectedException(EncounterFileRejection::HeaderMismatch);
        }

        if (\count($file->rows) !== $manifest->rows) {
            throw new EncounterFileRejectedException(EncounterFileRejection::RowCountMismatch);
        }

        return [$manifest, $fields];
    }

    /**
     * Fields of every version of the chosen schema, or of every schema when it is unknown, by name.
     *
     * @return array<string, EncounterField>
     */
    private function knownFields(string $schema): array
    {
        $dimensions = $this->dimensions->has($schema)
            ? [$this->dimensions->get($schema)]
            : array_map($this->dimensions->get(...), $this->dimensions->schemas());

        $fields = [];
        foreach ($dimensions as $dimension) {
            foreach ($dimension->versions() as $version) {
                foreach ($dimension->fields($version) as $field) {
                    $fields[$field->name] = $field;
                }
            }
        }

        return $fields;
    }

    /**
     * @param list<string>                  $header
     * @param array<string, EncounterField> $knownFields
     */
    private function assertNoIdentifyingColumns(array $header, array $knownFields): void
    {
        foreach ($header as $column) {
            if (!isset($knownFields[$column]) && $this->contentDetector->isIdentifyingColumn($column)) {
                throw new EncounterFileRejectedException(EncounterFileRejection::RawExport);
            }
        }
    }

    /**
     * @param array<string, EncounterField> $knownFields
     */
    private function assertNoIdentifyingContent(EncounterFile $file, array $knownFields): void
    {
        $types = [];
        foreach ($file->header as $index => $column) {
            $types[$index] = ($knownFields[$column] ?? null)?->type;
        }

        foreach ($file->rows as $cells) {
            foreach ($cells as $index => $value) {
                $type = $types[$index] ?? null;
                if ($this->contentDetector->isIdentifyingCell($value, EncounterFieldType::Timestamp === $type, EncounterFieldType::CodeSet === $type)) {
                    throw new EncounterFileRejectedException(EncounterFileRejection::IdentifyingContent);
                }
            }
        }
    }
}
