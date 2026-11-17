<?php

declare(strict_types=1);

namespace App\Encounter\Contract\DataPrep;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * What a schema adds to the offline data preparation tool (#1301): its schema file, its source profiles, an
 * optional script module, catalogs and an optional local map. The core tool is the shell (key file, keyed values,
 * manifest, export boundary, versions); without any contribution the tool and its pages are hidden.
 *
 * Paths are absolute and must resolve from the installed package (build them from the bundle path or the project
 * directory, never from the working directory). The schema file holds the fields of one version; the key rules
 * come from the dimension (EncounterDimensionInterface::keyRules()), never from the file.
 */
#[AutoconfigureTag(self::TAG)]
interface DataPrepContributionInterface
{
    public const string TAG = 'encounter.data_prep_contribution';

    /**
     * Schema name as in the manifest (`schema=…`) and in the profiles (`schema`).
     */
    public function schema(): string;

    /**
     * Absolute path of the schema file the tool writes (`schema`, `version`, `fields`).
     */
    public function schemaFile(): string;

    /**
     * Absolute paths of the source profiles; each names this schema.
     *
     * @return list<string>
     */
    public function profileFiles(): array;

    /**
     * Absolute path of the script module that registers the schema's own rule types and hooks
     * (`EncounterDataPrep.registerSchema()`), or null for a schema that works with the generic rule types alone.
     */
    public function scriptFile(): ?string;

    /**
     * Catalogs bundled into the tool as offline fallback, refreshed from their endpoint at start.
     *
     * @return list<DataPrepCatalog>
     */
    public function catalogs(): array;

    /**
     * The hospital's private local map of this schema (format, template and example files, page values), or null.
     */
    public function localMap(): ?DataPrepLocalMap;
}
