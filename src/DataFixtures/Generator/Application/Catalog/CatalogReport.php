<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Catalog;

/**
 * The result of checking the generator vocabulary against the installed catalog.
 */
final readonly class CatalogReport
{
    /**
     * @param array<string, list<CatalogMatch>> $sections      section (type, `speciality of department`, `indication`) → matches
     * @param list<string>                      $mappingErrors entries of the mapping file that bind nothing the generator uses
     */
    public function __construct(
        public array $sections,
        public array $mappingErrors = [],
        public ?string $mappingSource = null,
    ) {
    }

    public function isCompatible(): bool
    {
        return [] === $this->errors();
    }

    /**
     * @return list<string> one line per missing or ambiguous name, and per mapping error
     */
    public function errors(): array
    {
        $errors = [];
        foreach ($this->sections as $section => $matches) {
            foreach ($matches as $match) {
                if (!$match->isResolved()) {
                    $errors[] = sprintf('%s "%s" is %s: %s', $section, $match->name, $match->status, $match->problem ?? 'no reason given.');
                }
            }
        }
        foreach ($this->mappingErrors as $error) {
            $errors[] = 'mapping: '.$error;
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];
        foreach ($this->sections as $section => $matches) {
            foreach ($matches as $match) {
                if (null !== $match->warning) {
                    $warnings[] = sprintf('%s "%s": %s', $section, $match->name, $match->warning);
                }
            }
        }

        return $warnings;
    }

    /**
     * @return array{resolved: int, missing: int, ambiguous: int}
     */
    public function counts(string $section): array
    {
        $matches = $this->sections[$section] ?? [];
        $count = static fn (string $status): int => \count(array_filter($matches, static fn (CatalogMatch $match): bool => $status === $match->status));

        return ['resolved' => $count(CatalogMatch::RESOLVED), 'missing' => $count(CatalogMatch::MISSING), 'ambiguous' => $count(CatalogMatch::AMBIGUOUS)];
    }
}
