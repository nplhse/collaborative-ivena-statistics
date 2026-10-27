<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Faker\Provider;

use Faker\Provider\Base;
use Random\Randomizer;

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
        return self::rawLabelFor($code, RandomDraw::fromFaker());
    }

    /**
     * The same as rawIndicationLabel(), drawn from the given stream (ADR 022).
     */
    public static function rawLabelFor(string $code, Randomizer $random): string
    {
        $official = PzcCodes::name($code);

        $kinds = 0 === $random->getInt(0, 1) ? ['abbreviation', 'typo'] : ['typo', 'abbreviation'];
        foreach ($kinds as $kind) {
            $variant = match ($kind) {
                'abbreviation' => strtr($official, self::ABBREVIATIONS),
                'typo' => self::typo($official, $random),
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
        return self::unmappedFor(RandomDraw::fromFaker());
    }

    /**
     * The same as unmappedRawIndication(), drawn from the given stream (ADR 022).
     *
     * @return array{code: string, name: string}
     */
    public static function unmappedFor(Randomizer $random): array
    {
        return 1 === $random->getInt(0, 1)
            ? ['code' => RandomDraw::element(PzcCodes::all(), $random)['code'], 'name' => '']
            : ['code' => (string) $random->getInt(900, 999), 'name' => RandomDraw::element(['Unklare Lage', 'Zuweisung ohne Angabe', 'Sonstiges'], $random)];
    }

    /**
     * Swaps two neighbouring letters inside the longest word.
     */
    private static function typo(string $label, Randomizer $random): string
    {
        $words = explode(' ', $label);
        usort($words, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $word = $words[0];
        $length = mb_strlen($word);
        if ($length < 5) {
            return $label;
        }

        $at = $random->getInt(1, $length - 3);
        $swapped = mb_substr($word, 0, $at).mb_substr($word, $at + 1, 1).mb_substr($word, $at, 1).mb_substr($word, $at + 2);

        return preg_replace('/'.preg_quote($word, '/').'/u', $swapped, $label, 1) ?? $label;
    }
}
