<?php

declare(strict_types=1);

namespace App\Tests\Support\Encounter;

/**
 * Builds small synthetic canonical exchange files of any encounter schema (exchange-format.md):
 * manifest line, exact header and one line per row. Rows are cell values by field name; missing cells
 * stay empty. Schema-specific builders (EdVisitFileBuilder) add their defaults on top. Never real data.
 */
final class EncounterFileBuilder
{
    /** Check value of the synthetic test key; the first upload of a hospital registers it. */
    public const string KEY_CHECK = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @var array<string, string> */
    private array $manifest;

    /** @var list<array<string, string>> */
    private array $rows = [];

    private ?int $declaredRows = null;

    /**
     * @param list<string> $header
     */
    private function __construct(private array $header, string $schema, int $schemaVersion, string $fingerprintRule, string $profile)
    {
        $this->manifest = [
            'schema' => $schema,
            'schema_version' => (string) $schemaVersion,
            'profile' => $profile,
            'profile_version' => '1',
            'tool' => '1.0.0',
            'key_check' => self::KEY_CHECK,
            'fingerprint' => $fingerprintRule,
        ];
    }

    /**
     * @param list<string> $header field names of the schema version in header order
     */
    public static function for(string $schema, array $header, string $fingerprintRule, int $schemaVersion = 1, string $profile = 'synthetic'): self
    {
        return new self($header, $schema, $schemaVersion, $fingerprintRule, $profile);
    }

    /**
     * SHA-256 hex value standing in for the case pseudonym of a case label. The same label gives the
     * same pseudonym in files of every schema, so records of one case can be built across schemas.
     */
    public static function caseKey(string $case): string
    {
        return hash('sha256', 'case|'.$case);
    }

    /**
     * @param array<string, string> $cells cell values by field name
     */
    public function row(array $cells): self
    {
        $this->rows[] = $cells;

        return $this;
    }

    public function manifest(string $key, ?string $value): self
    {
        if (null === $value) {
            unset($this->manifest[$key]);
        } else {
            $this->manifest[$key] = $value;
        }

        return $this;
    }

    public function declaredRows(int $rows): self
    {
        $this->declaredRows = $rows;

        return $this;
    }

    /**
     * @param list<string> $header
     */
    public function header(array $header): self
    {
        $this->header = $header;

        return $this;
    }

    public function build(): string
    {
        $manifest = $this->manifest + ['rows' => (string) ($this->declaredRows ?? \count($this->rows))];
        $pairs = array_map(static fn (string $key, string $value): string => $key.'='.$value, array_keys($manifest), $manifest);

        $lines = ['#encounter-exchange;'.implode(';', $pairs), implode(';', $this->header)];
        foreach ($this->rows as $row) {
            $lines[] = implode(';', array_map(static fn (string $name): string => $row[$name] ?? '', $this->header));
        }

        return implode("\n", $lines)."\n";
    }
}
