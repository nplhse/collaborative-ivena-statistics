<?php

declare(strict_types=1);

namespace App\Encounter\Application\Import;

use App\Encounter\Domain\Enum\EncounterFileRejection;

/**
 * Line 1 of a canonical exchange file:
 * `#encounter-exchange;schema=ed_visit;schema_version=1;profile=…;profile_version=…;tool=…;key_check=…;fingerprint=ed-visit:v1;rows=…`.
 *
 * Parsing checks presence and format; whether the values are accepted is EncounterFileValidator's job.
 * Unknown keys are ignored, so a later tool may add keys.
 */
final readonly class EncounterManifest
{
    private const array REQUIRED = ['schema', 'schema_version', 'profile', 'profile_version', 'tool', 'key_check', 'fingerprint', 'rows'];

    private function __construct(
        public string $schema,
        public int $schemaVersion,
        public string $profile,
        public string $profileVersion,
        public string $toolVersion,
        public string $keyCheck,
        public string $fingerprintRule,
        public int $rows,
    ) {
    }

    /**
     * @throws EncounterFileRejectedException ManifestInvalid
     */
    public static function parse(string $line): self
    {
        $parts = explode(EncounterFileReader::DELIMITER, $line);
        if (EncounterFileReader::MANIFEST_PREFIX !== array_shift($parts)) {
            throw self::invalid();
        }

        $values = [];
        foreach ($parts as $part) {
            $pair = explode('=', $part, 2);
            if (2 !== \count($pair) || '' === $pair[0] || isset($values[$pair[0]])) {
                throw self::invalid();
            }

            $values[$pair[0]] = trim($pair[1]);
        }

        $required = [];
        foreach (self::REQUIRED as $key) {
            $value = $values[$key] ?? '';
            if ('' === $value) {
                throw self::invalid();
            }

            $required[$key] = $value;
        }

        if (1 !== preg_match('/^[1-9]\d{0,3}$/', $required['schema_version'])
            || 1 !== preg_match('/^(0|[1-9]\d{0,8})$/', $required['rows'])
            || 1 !== preg_match('/^[0-9a-f]{64}$/', $required['key_check'])) {
            throw self::invalid();
        }

        return new self(
            schema: $required['schema'],
            schemaVersion: (int) $required['schema_version'],
            profile: $required['profile'],
            profileVersion: $required['profile_version'],
            toolVersion: $required['tool'],
            keyCheck: $required['key_check'],
            fingerprintRule: $required['fingerprint'],
            rows: (int) $required['rows'],
        );
    }

    private static function invalid(): EncounterFileRejectedException
    {
        return new EncounterFileRejectedException(EncounterFileRejection::ManifestInvalid);
    }
}
