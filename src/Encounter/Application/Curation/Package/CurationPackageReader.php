<?php

declare(strict_types=1);

namespace App\Encounter\Application\Curation\Package;

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
 * Reads an encounter curation package and validates it completely before anything is written (#1198, as ADR 020).
 *
 * 1. Format and version are checked first; another format or version is never read further.
 * 2. Structure and types: strict denormalization into the package DTOs, all errors collected.
 * 3. Field rules: Validator constraints on the DTOs.
 * 4. Package rules: unique codes, references, counts and checksum.
 */
final readonly class CurationPackageReader
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private DenormalizerInterface $denormalizer,
        private ValidatorInterface $validator,
    ) {
    }

    public function read(string $path): CurationReadResult
    {
        if (!is_file($path) || !is_readable($path)) {
            return CurationReadResult::invalid([new CurationViolation('', sprintf('File not found or not readable: %s', $path))]);
        }

        $json = file_get_contents($path);
        if (false === $json) {
            return CurationReadResult::invalid([new CurationViolation('', sprintf('File could not be read: %s', $path))]);
        }

        return $this->readJson($json);
    }

    public function readJson(string $json): CurationReadResult
    {
        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return CurationReadResult::invalid([new CurationViolation('', sprintf('Invalid JSON: %s', $exception->getMessage()))]);
        }

        if (!\is_array($data)) {
            return CurationReadResult::invalid([new CurationViolation('', 'A package must be a JSON object.')]);
        }

        $unsupported = $this->checkFormat($data);
        if ($unsupported instanceof CurationViolation) {
            return CurationReadResult::invalid([$unsupported]);
        }

        try {
            $package = $this->denormalizer->denormalize($data, CurationPackage::class, 'json', [
                DenormalizerInterface::COLLECT_DENORMALIZATION_ERRORS => true,
                AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false,
            ]);
        } catch (PartialDenormalizationException $exception) {
            return CurationReadResult::invalid($this->fromDenormalizationErrors($exception->getNotNormalizableValueErrors(), $exception->getExtraAttributesError()));
        } catch (ExtraAttributesException $exception) {
            return CurationReadResult::invalid([$this->fromExtraAttributes($exception)]);
        } catch (SerializerException $exception) {
            return CurationReadResult::invalid([new CurationViolation('', sprintf('The package structure is invalid: %s', $exception->getMessage()))]);
        }

        if (!$package instanceof CurationPackage) {
            return CurationReadResult::invalid([new CurationViolation('', 'The package structure is invalid.')]);
        }

        $violations = $this->fromConstraintViolations($this->validator->validate($package));
        if ([] === $violations) {
            $violations = $this->fromConstraintViolations($this->validator->validate($package, null, [CurationPackage::PACKAGE_RULES]));
        }

        return [] === $violations ? CurationReadResult::valid($package) : CurationReadResult::invalid($violations);
    }

    /**
     * @param array<mixed> $data
     */
    private function checkFormat(array $data): ?CurationViolation
    {
        $meta = $data['meta'] ?? null;
        $format = \is_array($meta) ? ($meta['format'] ?? null) : null;
        $version = \is_array($meta) ? ($meta['version'] ?? null) : null;

        if (CurationPackageFormat::NAME === $format && CurationPackageFormat::VERSION === $version) {
            return null;
        }

        return new CurationViolation('meta', sprintf(
            'Unsupported package: expected format "%s" version %d, found format %s version %s. Export the package again with this application version.',
            CurationPackageFormat::NAME,
            CurationPackageFormat::VERSION,
            \is_string($format) ? sprintf('"%s"', $format) : 'none',
            \is_int($version) ? (string) $version : 'none',
        ));
    }

    /**
     * @param array<NotNormalizableValueException> $errors
     *
     * @return non-empty-list<CurationViolation>
     */
    private function fromDenormalizationErrors(array $errors, ?ExtraAttributesException $extraAttributes): array
    {
        $violations = $extraAttributes instanceof ExtraAttributesException ? [$this->fromExtraAttributes($extraAttributes)] : [];
        foreach ($errors as $error) {
            $expected = $error->getExpectedTypes();
            $message = null !== $expected && [] !== $expected
                ? sprintf('Expected %s, found %s.', implode(' or ', $expected), $error->getCurrentType() ?? 'nothing')
                : ($error->canUseMessageForUser() ? $error->getMessage() : 'Invalid value.');

            $violations[] = new CurationViolation($this->jsonPath($error->getPath() ?? ''), $message);
        }

        return [] === $violations ? [new CurationViolation('', 'The package structure is invalid.')] : $violations;
    }

    private function fromExtraAttributes(ExtraAttributesException $exception): CurationViolation
    {
        return new CurationViolation('', sprintf('Unknown field(s): %s', implode(', ', $exception->getExtraAttributes())));
    }

    /**
     * @return list<CurationViolation>
     */
    private function fromConstraintViolations(ConstraintViolationListInterface $list): array
    {
        $violations = [];
        foreach ($list as $violation) {
            $invalid = $violation->getInvalidValue();
            $violations[] = new CurationViolation(
                $this->jsonPath($violation->getPropertyPath()),
                (string) $violation->getMessage(),
                \is_scalar($invalid) ? (string) $invalid : null,
            );
        }

        return $violations;
    }

    /**
     * Turns a property path (`problemAreas[0].germanName`) into the key path used in the file
     * (`problem_areas[0].german_name`).
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
