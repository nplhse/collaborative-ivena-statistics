<?php

declare(strict_types=1);

namespace App\Encounter\Application\Catalog;

/**
 * The Manchester Triage System catalog (#1056): German names of the presentation diagrams and
 * indicators in `mts-de.json`, the single source for the data preparation tool (bundled as catalog
 * `mts-de`) and the MTS glossary in Explore.
 *
 * Codes are derived from the German name exactly as the tool's `catalogCode()` does: lowercase,
 * ä/ö/ü/ß → ae/oe/ue/ss, other diacritics removed, runs of other characters → `_`, cut at 64
 * characters, no leading or trailing `_`. Both lists are open: the full MTS has more entries.
 */
final class MtsCatalog
{
    public const string ID = 'mts-de';

    public const string FILE = __DIR__.'/mts-de.json';

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    /** @var array<string, MtsCatalogEntry>|null */
    private ?array $diagrams = null;

    /** @var array<string, MtsCatalogEntry>|null */
    private ?array $indicators = null;

    /**
     * The catalog file as the tool reads it (`id`, `label`, `note`, `diagrams`, `indicators`).
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        if (null === $this->data) {
            $content = file_get_contents(self::FILE);
            if (false === $content) {
                throw new \RuntimeException(sprintf('Cannot read the MTS catalog "%s".', self::FILE));
            }

            /** @var array<string, mixed> $data */
            $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
            $this->data = $data;
        }

        return $this->data;
    }

    /**
     * Diagrams ordered by code (that is, alphabetically by German name).
     *
     * @return array<string, MtsCatalogEntry> keyed by code
     */
    public function diagrams(): array
    {
        return $this->diagrams ??= $this->entries('diagrams');
    }

    /**
     * Indicators ordered by code (that is, alphabetically by German name).
     *
     * @return array<string, MtsCatalogEntry> keyed by code
     */
    public function indicators(): array
    {
        return $this->indicators ??= $this->entries('indicators');
    }

    public function diagram(string $code): ?MtsCatalogEntry
    {
        return $this->diagrams()[$code] ?? null;
    }

    public function indicator(string $code): ?MtsCatalogEntry
    {
        return $this->indicators()[$code] ?? null;
    }

    /**
     * Code of a German MTS name, identical to the tool's `catalogCode()`.
     */
    public static function code(string $label): string
    {
        $value = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $label)));
        $value = strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $value = (string) \Normalizer::normalize($value, \Normalizer::FORM_D);
        $value = (string) preg_replace('/[\x{0300}-\x{036f}]/u', '', $value);
        $value = (string) preg_replace('/[^a-z0-9]+/', '_', $value);

        return trim(substr($value, 0, 64), '_');
    }

    /**
     * @return array<string, MtsCatalogEntry>
     */
    private function entries(string $list): array
    {
        $labels = $this->data()[$list] ?? null;
        if (!\is_array($labels)) {
            throw new \RuntimeException(sprintf('The MTS catalog has no list "%s".', $list));
        }

        $entries = [];
        foreach ($labels as $label) {
            if (!\is_string($label)) {
                throw new \RuntimeException(sprintf('The MTS catalog list "%s" contains a non-string entry.', $list));
            }

            $code = self::code($label);
            if (isset($entries[$code])) {
                throw new \RuntimeException(sprintf('The MTS catalog list "%s" contains the code "%s" twice.', $list, $code));
            }

            $entries[$code] = new MtsCatalogEntry($code, $label);
        }

        ksort($entries, \SORT_STRING);

        return $entries;
    }
}
