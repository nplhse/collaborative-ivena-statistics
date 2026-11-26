<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Application\Curation\Package;

/**
 * Name, version and checksum algorithm of the encounter curation package (#1198): the curated MTS catalog and the
 * plausibility problem areas as one JSON file. Only the current version is read; an older file is exported again.
 */
final class CurationPackageFormat
{
    public const string NAME = 'ivena-encounter-curation';

    public const int VERSION = 1;

    public const string CHECKSUM_ALGORITHM = 'sha256';

    /** Section keys in file order, also the keys of `meta.counts`. */
    public const array SECTIONS = ['diagrams', 'indicators', 'aliases', 'assignments', 'problem_areas'];

    /** Codes of entries, aliases and problem areas: the slugs of MtsCatalog::code(). */
    public const string CODE_PATTERN = '/^[a-z0-9_]{1,64}$/';
}
