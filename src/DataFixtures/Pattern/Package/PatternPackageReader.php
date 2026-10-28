<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Package;

use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Reads an allocation pattern package and validates it completely before anything is generated (ADR 022).
 *
 * 1. Format and version are checked first; another format or version is never read further.
 * 2. Structure: known keys, required keys, numbers and shares in range (PatternPackageStructure).
 * 3. Package rules: segments, table keys, sums, percentiles, counts and checksum (ValidPatternPackage).
 * 4. Catalog, when one is given: every name and PZC code exists in the selected reference catalog.
 */
final readonly class PatternPackageReader
{
    public function __construct(
        private ValidatorInterface $validator,
    ) {
    }

    public function read(string $path, ?PatternCatalogNames $catalog = null): PatternPackageReadResult
    {
        if (!is_file($path) || !is_readable($path)) {
            return PatternPackageReadResult::invalid([new PatternPackageViolation('', sprintf('File not found or not readable: %s', $path))]);
        }

        $json = file_get_contents($path);
        if (false === $json) {
            return PatternPackageReadResult::invalid([new PatternPackageViolation('', sprintf('File could not be read: %s', $path))]);
        }

        return $this->readJson($json, $catalog);
    }

    public function readJson(string $json, ?PatternCatalogNames $catalog = null): PatternPackageReadResult
    {
        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return PatternPackageReadResult::invalid([new PatternPackageViolation('', sprintf('Invalid JSON: %s', $exception->getMessage()))]);
        }

        if (!\is_array($data)) {
            return PatternPackageReadResult::invalid([new PatternPackageViolation('', 'A package must be a JSON object.')]);
        }

        $unsupported = $this->checkFormat($data);
        if ($unsupported instanceof PatternPackageViolation) {
            return PatternPackageReadResult::invalid([$unsupported]);
        }

        $violations = $this->fromConstraintViolations($this->validator->validate($data, PatternPackageStructure::constraint()));
        if ([] !== $violations) {
            return PatternPackageReadResult::invalid($violations);
        }

        /** @var array<string, mixed> $data */
        $package = PatternPackage::fromArray($data);
        $violations = $this->fromConstraintViolations($this->validator->validate($package));
        if ([] === $violations && $catalog instanceof PatternCatalogNames) {
            $violations = PatternCatalogCheck::check($package, $catalog);
        }

        return [] === $violations ? PatternPackageReadResult::valid($package) : PatternPackageReadResult::invalid($violations);
    }

    /**
     * @param array<mixed> $data
     */
    private function checkFormat(array $data): ?PatternPackageViolation
    {
        $meta = $data['meta'] ?? null;
        $format = \is_array($meta) ? ($meta['format'] ?? null) : null;
        $version = \is_array($meta) ? ($meta['version'] ?? null) : null;

        if (PatternPackageFormat::NAME === $format && PatternPackageFormat::VERSION === $version) {
            return null;
        }

        return new PatternPackageViolation('meta', sprintf(
            'Unsupported package: expected format "%s" version %d, found format %s version %s.',
            PatternPackageFormat::NAME,
            PatternPackageFormat::VERSION,
            \is_string($format) ? sprintf('"%s"', $format) : 'none',
            \is_int($version) ? (string) $version : 'none',
        ));
    }

    /**
     * @return list<PatternPackageViolation>
     */
    private function fromConstraintViolations(ConstraintViolationListInterface $list): array
    {
        $violations = [];
        foreach ($list as $violation) {
            $invalid = $violation->getInvalidValue();
            $violations[] = new PatternPackageViolation(
                $this->path($violation->getPropertyPath()),
                (string) $violation->getMessage(),
                \is_scalar($invalid) ? (string) $invalid : null,
            );
        }

        return $violations;
    }

    /**
     * Turns the path of the structure check (`[segments][urban-full][hours][3]`) into a key path
     * (`segments.urban-full.hours.3`); paths of the package rules are key paths already.
     */
    private function path(string $propertyPath): string
    {
        if (!str_starts_with($propertyPath, '[')) {
            return $propertyPath;
        }

        return implode('.', explode('][', substr($propertyPath, 1, -1)));
    }
}
