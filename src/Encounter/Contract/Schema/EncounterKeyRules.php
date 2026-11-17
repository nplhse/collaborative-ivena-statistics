<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Schema;

/**
 * How the keyed values of a schema version are built (ADR 027, ADR 029, #1301): the record fingerprint and the
 * case key, both HMAC-SHA256 with the hospital's pseudonym key over `rule|part|part…`. The data preparation tool
 * gets these rules rendered from here; tools and fixtures must not keep their own copy.
 *
 * Parts: `case_number` (the source case number, digits only), `anchor_source` (the name of the time anchor
 * column the profile used) and `anchor` (the anchor time, `YYYY-MM-DDTHH:MM:SS`).
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the tool renderer and the synthetic data.
 */
final readonly class EncounterKeyRules
{
    public const string PART_CASE_NUMBER = 'case_number';

    public const string PART_ANCHOR_SOURCE = 'anchor_source';

    public const string PART_ANCHOR = 'anchor';

    private const array PARTS = [self::PART_CASE_NUMBER, self::PART_ANCHOR_SOURCE, self::PART_ANCHOR];

    /**
     * @param list<string> $fingerprintParts
     * @param list<string> $caseKeyParts
     */
    public function __construct(
        public string $fingerprintField,
        public string $fingerprintRule,
        public array $fingerprintParts,
        public string $caseKeyField,
        public string $caseKeyPrefix,
        public array $caseKeyParts = [self::PART_CASE_NUMBER],
    ) {
        foreach ([...$fingerprintParts, ...$caseKeyParts] as $part) {
            if (!\in_array($part, self::PARTS, true)) {
                throw new \InvalidArgumentException(sprintf('Unknown key part "%s".', $part));
            }
        }
    }

    /**
     * Digits of a source case number, as the tool normalises it (`0001-2345` → `00012345`).
     */
    public static function caseNumber(string $source): string
    {
        return (string) preg_replace('/\D/', '', $source);
    }

    public function fingerprintInput(string $caseNumber, ?string $anchorSource, ?\DateTimeImmutable $anchor): string
    {
        return $this->input($this->fingerprintRule, $this->fingerprintParts, $caseNumber, $anchorSource, $anchor);
    }

    public function caseKeyInput(string $caseNumber): string
    {
        return $this->input($this->caseKeyPrefix, $this->caseKeyParts, $caseNumber, null, null);
    }

    /**
     * The block the tool reads (`keys` of the rendered schema).
     *
     * @return array{fingerprint: array{field: string, rule: string, parts: list<string>}, caseKey: array{field: string, prefix: string, parts: list<string>}}
     */
    public function toArray(): array
    {
        return [
            'fingerprint' => ['field' => $this->fingerprintField, 'rule' => $this->fingerprintRule, 'parts' => $this->fingerprintParts],
            'caseKey' => ['field' => $this->caseKeyField, 'prefix' => $this->caseKeyPrefix, 'parts' => $this->caseKeyParts],
        ];
    }

    /**
     * @param list<string> $parts
     */
    private function input(string $rule, array $parts, string $caseNumber, ?string $anchorSource, ?\DateTimeImmutable $anchor): string
    {
        $values = [$rule];
        foreach ($parts as $part) {
            $values[] = match ($part) {
                self::PART_CASE_NUMBER => self::caseNumber($caseNumber),
                self::PART_ANCHOR_SOURCE => $anchorSource ?? throw new \InvalidArgumentException('The rule needs the anchor source.'),
                default => ($anchor ?? throw new \InvalidArgumentException('The rule needs the anchor.'))->format('Y-m-d\TH:i:s'),
            };
        }

        return implode('|', $values);
    }
}
