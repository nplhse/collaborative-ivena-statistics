<?php

declare(strict_types=1);

namespace App\Encounter\Application\Catalog;

use App\Encounter\Application\Contract\MtsCatalogReaderInterface;
use App\Encounter\Domain\Enum\MtsCatalogKind;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The Manchester Triage System catalog (ADR 032, #1082): diagrams and indicators maintained in the database, read
 * by the MTS glossary in Explore and the encounter pages.
 *
 * Resolution: an active entry (curated or provisional) resolves to itself; a merged entry and any other alias code
 * resolve through their alias to the active target, in one step. A code that resolves to nothing has no entry and
 * is shown as a code by the callers. Encounters keep the code as sent; only reading resolves it.
 *
 * The data preparation tool still bundles `mts-de.json` (data()) until #1157 gives it a catalog endpoint.
 *
 * Codes are derived from the German name exactly as the tool's `catalogCode()` does: lowercase,
 * ä/ö/ü/ß → ae/oe/ue/ss, other diacritics removed, runs of other characters → `_`, cut at 64
 * characters, no leading or trailing `_`. Both lists are open: the full MTS has more entries.
 */
final class MtsCatalog implements ResetInterface
{
    public const string ID = 'mts-de';

    /** The tool's bundled copy of the catalog. */
    public const string FILE = __DIR__.'/mts-de.json';

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    /** @var array<string, array{entries: array<string, MtsCatalogEntry>, aliases: array<string, string>}> keyed by kind */
    private array $lists = [];

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private readonly MtsCatalogReaderInterface $reader,
    ) {
    }

    /**
     * The catalog file the data preparation tool bundles (`id`, `label`, `note`, `diagrams`, `indicators`).
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data ??= self::bundledData();
    }

    /**
     * @return array<string, mixed>
     */
    public static function bundledData(): array
    {
        $content = file_get_contents(self::FILE);
        if (false === $content) {
            throw new \RuntimeException(\sprintf('Cannot read the MTS catalog "%s".', self::FILE));
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * Active diagrams ordered by code (for seeded entries: alphabetically by German name).
     *
     * @return array<string, MtsCatalogEntry> keyed by code
     */
    public function diagrams(): array
    {
        return $this->list(MtsCatalogKind::Diagram)['entries'];
    }

    /**
     * Active indicators ordered by code (for seeded entries: alphabetically by German name).
     *
     * @return array<string, MtsCatalogEntry> keyed by code
     */
    public function indicators(): array
    {
        return $this->list(MtsCatalogKind::Indicator)['entries'];
    }

    /**
     * The active diagram a code resolves to, or null; for an alias code this is the target entry with its own code.
     */
    public function diagram(string $code): ?MtsCatalogEntry
    {
        return $this->resolve(MtsCatalogKind::Diagram, $code);
    }

    /**
     * The active indicator a code resolves to, or null; for an alias code this is the target entry with its own code.
     */
    public function indicator(string $code): ?MtsCatalogEntry
    {
        return $this->resolve(MtsCatalogKind::Indicator, $code);
    }

    public function resolve(MtsCatalogKind $kind, string $code): ?MtsCatalogEntry
    {
        $list = $this->list($kind);
        if (isset($list['entries'][$code])) {
            return $list['entries'][$code];
        }

        $target = $list['aliases'][$code] ?? null;

        return null === $target ? null : $list['entries'][$target] ?? null;
    }

    /**
     * The code of an active entry and every alias code that resolves to it, entry code first, aliases by code.
     *
     * @return list<string>
     */
    public function codesResolvingTo(MtsCatalogKind $kind, string $code): array
    {
        $aliases = array_keys(array_filter(
            $this->list($kind)['aliases'],
            static fn (string $target): bool => $target === $code,
        ));
        sort($aliases, \SORT_STRING);

        return [$code, ...$aliases];
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

    #[\Override]
    public function reset(): void
    {
        $this->lists = [];
    }

    /**
     * @return array{entries: array<string, MtsCatalogEntry>, aliases: array<string, string>}
     */
    private function list(MtsCatalogKind $kind): array
    {
        if (isset($this->lists[$kind->value])) {
            return $this->lists[$kind->value];
        }

        $entries = [];
        foreach ($this->reader->entries($kind) as $row) {
            if ($row['status']->isActive()) {
                $entries[$row['code']] = new MtsCatalogEntry($row['code'], $row['german_name'], $row['english_name']);
            }
        }

        ksort($entries, \SORT_STRING);

        // An alias resolves only to an active entry; one that does not (maintenance prevents it) resolves to nothing.
        $aliases = array_filter(
            $this->reader->aliases($kind),
            static fn (string $target, string $code): bool => isset($entries[$target]) && !isset($entries[$code]),
            \ARRAY_FILTER_USE_BOTH,
        );

        return $this->lists[$kind->value] = ['entries' => $entries, 'aliases' => $aliases];
    }
}
