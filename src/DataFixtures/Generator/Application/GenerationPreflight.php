<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application;

use App\DataFixtures\FixtureVolume;
use App\DataFixtures\Generator\Application\Catalog\CatalogCompatibility;
use App\DataFixtures\Generator\Application\Catalog\CatalogMismatchException;
use App\DataFixtures\Pattern\Application\InvalidPatternPackageException;
use App\DataFixtures\Pattern\Application\PatternSetLoader;

/**
 * What every generator run checks before its first row (ADR 022): the pattern source of the volume
 * loads and validates, and the installed catalog has every name and PZC code of that pattern set.
 */
final readonly class GenerationPreflight
{
    public function __construct(
        private PatternSetLoader $patterns,
        private CatalogCompatibility $catalog,
    ) {
    }

    /**
     * @throws InvalidPatternPackageException when the pattern package is missing or invalid
     * @throws CatalogMismatchException       when the catalog cannot serve the generator
     * @throws \InvalidArgumentException      when the catalog mapping file is invalid
     */
    public function check(FixtureVolume $volume): void
    {
        $this->catalog->assertCompatible($this->patterns->load($volume->patterns));
    }
}
