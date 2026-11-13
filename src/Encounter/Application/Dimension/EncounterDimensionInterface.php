<?php

declare(strict_types=1);

namespace App\Encounter\Application\Dimension;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A schema of the canonical encounter exchange format (ADR 026): a kind of record with fixed meaning,
 * independent of vendor formats. `ed_visit` is the first; `inpatient_stay`, `diagnosis` or `procedure`
 * would be further implementations.
 *
 * A schema provides its field contract per version. Parsing and validating files against it is the
 * job of the encounter import (#1048).
 */
#[AutoconfigureTag(self::TAG)]
interface EncounterDimensionInterface
{
    public const string TAG = 'encounter.dimension';

    /**
     * Schema name as in the manifest (`schema=…`).
     */
    public function schema(): string;

    /**
     * Schema versions the server accepts (`schema_version=…`), ascending.
     *
     * @return list<int>
     */
    public function versions(): array;

    /**
     * Fields of one version in header order; the file header must match their names exactly.
     *
     * @return list<EncounterField>
     *
     * @throws \InvalidArgumentException for a version not in versions()
     */
    public function fields(int $version): array;

    /**
     * Fingerprint rules (`fingerprint=…` in the manifest) the server accepts for one version. A rule
     * change gets a new rule name (ADR 029).
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException for a version not in versions()
     */
    public function fingerprintRules(int $version): array;
}
