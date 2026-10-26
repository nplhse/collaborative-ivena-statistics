<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Package;

use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerException;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Exception\PartialDenormalizationException;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Reads a reference catalog package and validates it completely before anyone writes (ADR 020).
 *
 * 1. Format and version are checked first; another format or version is never read further.
 * 2. Structure and types: strict denormalization into the package DTOs, all errors collected.
 * 3. Field rules: Validator constraints on the DTOs.
 * 4. Package rules: ids, natural keys, references, counts and checksum.
 */
final readonly class ReferenceCatalogPackageReader
{
    public function __construct(
        private DenormalizerInterface $denormalizer,
        private ValidatorInterface $validator,
    ) {
    }

    public function read(string $path): ReferenceCatalogReadResult
    {
        if (!is_file($path) || !is_readable($path)) {
            return ReferenceCatalogReadResult::invalid([new ReferenceCatalogViolation('', sprintf('File not found or not readable: %s', $path))]);
        }

        $json = file_get_contents($path);
        if (false === $json) {
            return ReferenceCatalogReadResult::invalid([new ReferenceCatalogViolation('', sprintf('File could not be read: %s', $path))]);
        }

        return $this->readJson($json);
    }

    public function readJson(string $json): ReferenceCatalogReadResult
    {
        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return ReferenceCatalogReadResult::invalid([new ReferenceCatalogViolation('', sprintf('Invalid JSON: %s', $exception->getMessage()))]);
        }

        if (!\is_array($data)) {
            return ReferenceCatalogReadResult::invalid([new ReferenceCatalogViolation('', 'A package must be a JSON object.')]);
        }

        $unsupported = $this->checkFormat($data);
        if ($unsupported instanceof ReferenceCatalogViolation) {
            return ReferenceCatalogReadResult::invalid([$unsupported]);
        }

        try {
            $package = $this->denormalizer->denormalize($data, ReferenceCatalogPackage::class, 'json', [
                DenormalizerInterface::COLLECT_DENORMALIZATION_ERRORS => true,
                AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false,
            ]);
        } catch (PartialDenormalizationException $exception) {
            return ReferenceCatalogReadResult::invalid($this->fromDenormalizationErrors($exception->getNotNormalizableValueErrors(), $exception->getExtraAttributesError()));
        } catch (ExtraAttributesException $exception) {
            return ReferenceCatalogReadResult::invalid([$this->fromExtraAttributes($exception)]);
        } catch (SerializerException $exception) {
            return ReferenceCatalogReadResult::invalid([new ReferenceCatalogViolation('', sprintf('The package structure is invalid: %s', $exception->getMessage()))]);
        }

        if (!$package instanceof ReferenceCatalogPackage) {
            return ReferenceCatalogReadResult::invalid([new ReferenceCatalogViolation('', 'The package structure is invalid.')]);
        }

        $violations = $this->fromConstraintViolations($this->validator->validate($package));
        if ([] === $violations) {
            $violations = $this->fromConstraintViolations($this->validator->validate($package, null, [ReferenceCatalogPackage::PACKAGE_RULES]));
        }

        return [] === $violations ? ReferenceCatalogReadResult::valid($package) : ReferenceCatalogReadResult::invalid($violations);
    }

    /**
     * @param array<mixed> $data
     */
    private function checkFormat(array $data): ?ReferenceCatalogViolation
    {
        $meta = $data['meta'] ?? null;
        $format = \is_array($meta) ? ($meta['format'] ?? null) : null;
        $version = \is_array($meta) ? ($meta['version'] ?? null) : null;

        if (ReferenceCatalogFormat::NAME === $format && ReferenceCatalogFormat::VERSION === $version) {
            return null;
        }

        return new ReferenceCatalogViolation('meta', sprintf(
            'Unsupported package: expected format "%s" version %d, found format %s version %s. Export the catalog again with this application version.',
            ReferenceCatalogFormat::NAME,
            ReferenceCatalogFormat::VERSION,
            \is_string($format) ? sprintf('"%s"', $format) : 'none',
            \is_int($version) ? (string) $version : 'none',
        ));
    }

    /**
     * @param array<NotNormalizableValueException> $errors
     *
     * @return non-empty-list<ReferenceCatalogViolation>
     */
    private function fromDenormalizationErrors(array $errors, ?ExtraAttributesException $extraAttributes): array
    {
        $violations = $extraAttributes instanceof ExtraAttributesException ? [$this->fromExtraAttributes($extraAttributes)] : [];
        foreach ($errors as $error) {
            $expected = $error->getExpectedTypes();
            $message = null !== $expected && [] !== $expected
                ? sprintf('Expected %s, found %s.', implode(' or ', $expected), $error->getCurrentType() ?? 'nothing')
                : ($error->canUseMessageForUser() ? $error->getMessage() : 'Invalid value.');

            $violations[] = new ReferenceCatalogViolation($this->jsonPath($error->getPath() ?? ''), $message);
        }

        return [] === $violations ? [new ReferenceCatalogViolation('', 'The package structure is invalid.')] : $violations;
    }

    private function fromExtraAttributes(ExtraAttributesException $exception): ReferenceCatalogViolation
    {
        return new ReferenceCatalogViolation('', sprintf('Unknown field(s): %s', implode(', ', $exception->getExtraAttributes())));
    }

    /**
     * @return list<ReferenceCatalogViolation>
     */
    private function fromConstraintViolations(ConstraintViolationListInterface $list): array
    {
        $violations = [];
        foreach ($list as $violation) {
            $invalid = $violation->getInvalidValue();
            $violations[] = new ReferenceCatalogViolation(
                $this->jsonPath($violation->getPropertyPath()),
                (string) $violation->getMessage(),
                \is_scalar($invalid) ? (string) $invalid : null,
            );
        }

        return $violations;
    }

    /**
     * Turns a property path (`dispatchAreas[0].postalCode`) into the key path used in the file
     * (`dispatch_areas[0].postal_code`).
     */
    private function jsonPath(string $propertyPath): string
    {
        $converter = new CamelCaseToSnakeCaseNameConverter();

        return (string) preg_replace_callback(
            '/[A-Za-z][A-Za-z0-9]*/',
            static fn (array $match): string => $converter->normalize($match[0]),
            $propertyPath,
        );
    }
}
