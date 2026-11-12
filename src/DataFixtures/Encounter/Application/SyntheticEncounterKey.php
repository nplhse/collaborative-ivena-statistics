<?php

declare(strict_types=1);

namespace App\DataFixtures\Encounter\Application;

use App\Encounter\Application\Dimension\EdVisitDimension;

/**
 * A pseudonym key for synthetic encounter data and the keyed values of `ed-visit:v1` (#1052).
 *
 * Same rules as the data preparation tool (exchange-format.md, ADR 026): the check value is
 * HMAC-SHA256(key, "encounter-key-check"), the visit fingerprint HMAC-SHA256(key,
 * "ed-visit:v1|" + case number + "|" + anchor source + "|" + anchor with seconds) and the case key
 * HMAC-SHA256(key, "case:v1|" + case number). Synthetic sources have minute precision, so the
 * seconds are `:00`. Test key only: derived from seed and hospital, never used for real data.
 */
final readonly class SyntheticEncounterKey
{
    public const string KEY_FILE_FORMAT = 'encounter-key';

    private function __construct(
        private string $raw,
    ) {
        if (32 !== \strlen($raw)) {
            throw new \InvalidArgumentException('An encounter key has 256 bits.');
        }
    }

    public static function forHospital(int $seed, string $hospital): self
    {
        return new self(hash('sha256', sprintf('synthetic-encounter-key|%d|%s', $seed, $hospital), true));
    }

    public static function fromHex(string $hex): self
    {
        $raw = 1 === preg_match('/^[0-9a-f]{64}$/', $hex) ? hex2bin($hex) : false;

        return false === $raw ? throw new \InvalidArgumentException('An encounter key is 64 lowercase hex digits.') : new self($raw);
    }

    public function hex(): string
    {
        return bin2hex($this->raw);
    }

    public function checkValue(): string
    {
        return hash_hmac('sha256', 'encounter-key-check', $this->raw);
    }

    /**
     * Content of a key file as the data preparation tool reads it.
     *
     * @return array{format: string, version: int, label: string, created: string, key: string, check: string}
     */
    public function keyFile(string $label, string $createdDay): array
    {
        return [
            'format' => self::KEY_FILE_FORMAT,
            'version' => 1,
            'label' => $label,
            'created' => $createdDay,
            'key' => $this->hex(),
            'check' => $this->checkValue(),
        ];
    }

    public function caseKey(string $caseNumber): string
    {
        return hash_hmac('sha256', 'case:v1|'.$this->digits($caseNumber), $this->raw);
    }

    /**
     * @param string $anchorSource `assessment` or `admission`
     * @param string $anchor       `YYYY-MM-DDTHH:MM` or `YYYY-MM-DDTHH:MM:SS`
     */
    public function visitFingerprint(string $caseNumber, string $anchorSource, string $anchor): string
    {
        $anchor = 16 === \strlen($anchor) ? $anchor.':00' : $anchor;

        return hash_hmac('sha256', sprintf('%s|%s|%s|%s', EdVisitDimension::FINGERPRINT_RULE_V1, $this->digits($caseNumber), $anchorSource, $anchor), $this->raw);
    }

    /**
     * Fingerprint and case key of a row in exchange form: anchor = first assessment contact, else ED admission.
     *
     * @param array<string, string> $cells
     *
     * @return array{visit_fingerprint: string, case_key: string}
     */
    public function keysOf(string $caseNumber, array $cells): array
    {
        $assessment = $cells['first_assessment_contact_at'] ?? '';
        [$source, $anchor] = '' !== $assessment ? ['assessment', $assessment] : ['admission', $cells['ed_admission_at'] ?? ''];
        if ('' === $anchor) {
            throw new \InvalidArgumentException('A row without time anchor has no fingerprint.');
        }

        return ['visit_fingerprint' => $this->visitFingerprint($caseNumber, $source, $anchor), 'case_key' => $this->caseKey($caseNumber)];
    }

    private function digits(string $caseNumber): string
    {
        return (string) preg_replace('/\D/', '', $caseNumber);
    }
}
