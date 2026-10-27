<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Faker\Provider;

use Faker\Provider\Base;

/**
 * Indications along the official PZC list (ADR 020, decision 6).
 *
 * Normalized indications use the official wording. Raw labels are generated variants of it
 * (abbreviation or typo; case and spacing would not count, the import ignores them), never taken from a curated catalog, so the
 * synthetic catalog contains non-identical raw → normalized mappings and unmapped labels.
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class Indication extends Base
{
    private const array ABBREVIATIONS = [
        'sonstiger' => 'sonst.',
        'sonstige' => 'sonst.',
        'Zu-Verlegung' => 'Zuverl.',
        'Verletzung' => 'Verl.',
        'Notfall' => 'NF',
        'Reanimation' => 'Rea',
        'Einweisung' => 'Einw.',
        'Erstversorgung' => 'Erstvers.',
        'Extremitäten' => 'Extr.',
        'akut' => 'ak.',
    ];

    /**
     * @return array{code: string, name: string}
     */
    public function indication(): array
    {
        return self::randomElement(PzcCodes::all());
    }

    public function indicationCode(): string
    {
        return $this->indication()['code'];
    }

    public function indicationName(): string
    {
        return $this->indication()['name'];
    }

    public function indicationCategory(string $code): string
    {
        return PzcCodes::category($code);
    }

    /**
     * A label as it may arrive in an IVENA file for this code; never the official wording itself.
     */
    public function rawIndicationLabel(string $code): string
    {
        $official = PzcCodes::name($code);

        /** @var list<'abbreviation'|'typo'> $kinds */
        $kinds = self::shuffleArray(['abbreviation', 'typo']);
        foreach ($kinds as $kind) {
            $variant = match ($kind) {
                'abbreviation' => strtr($official, self::ABBREVIATIONS),
                'typo' => $this->typo($official),
            };
            if ($variant !== $official) {
                return $variant;
            }
        }

        return $official.'.';
    }

    /**
     * A raw label the catalog cannot map: only a code, or a code outside the PZC list.
     *
     * @return array{code: string, name: string}
     */
    public function unmappedRawIndication(): array
    {
        return 1 === self::numberBetween(0, 1)
            ? ['code' => $this->indicationCode(), 'name' => '']
            : ['code' => (string) self::numberBetween(900, 999), 'name' => self::randomElement(['Unklare Lage', 'Zuweisung ohne Angabe', 'Sonstiges'])];
    }

    /**
     * Swaps two neighbouring letters inside the longest word.
     */
    private function typo(string $label): string
    {
        $words = explode(' ', $label);
        usort($words, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $word = $words[0];
        $length = mb_strlen($word);
        if ($length < 5) {
            return $label;
        }

        $at = self::numberBetween(1, $length - 3);
        $swapped = mb_substr($word, 0, $at).mb_substr($word, $at + 1, 1).mb_substr($word, $at, 1).mb_substr($word, $at + 2);

        return preg_replace('/'.preg_quote($word, '/').'/u', $swapped, $label, 1) ?? $label;
    }
}
