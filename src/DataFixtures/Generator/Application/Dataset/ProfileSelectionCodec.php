<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Dataset;

use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\DataFixtures\Generator\Application\ProfileSelection;
use App\DataFixtures\Structure\Dto\ObstetricsLevel;
use App\DataFixtures\Structure\Dto\StrokeUnitLevel;
use App\DataFixtures\Structure\Dto\TraumaLevel;

/**
 * A ProfileSelection as a plain array with snake_case keys, for the run settings (so a run can be repeated)
 * and the `--profiles` file of `app:generate:dataset`. Only set fields are written.
 */
final class ProfileSelectionCodec
{
    private const array KEYS = [
        'configuration', 'cathlab', 'neurosurgery', 'stroke_unit', 'trauma_level', 'obstetrics', 'paediatrics',
        'tier', 'size', 'beds', 'annual_volume', 'closure_share',
    ];

    /**
     * @return array<string, scalar>
     */
    public static function encode(ProfileSelection $selection): array
    {
        return array_filter([
            'configuration' => $selection->configuration,
            'cathlab' => $selection->cathlab,
            'neurosurgery' => $selection->neurosurgery,
            'stroke_unit' => $selection->strokeUnit?->value,
            'trauma_level' => $selection->traumaLevel?->value,
            'obstetrics' => $selection->obstetrics?->value,
            'paediatrics' => $selection->paediatrics,
            'tier' => $selection->tier?->value,
            'size' => $selection->size?->value,
            'beds' => $selection->beds,
            'annual_volume' => $selection->annualVolume,
            'closure_share' => $selection->closureShare,
        ], static fn (mixed $value): bool => null !== $value);
    }

    /**
     * @param array<mixed> $data
     *
     * @throws \InvalidArgumentException for unknown keys or values of the wrong type
     */
    public static function decode(array $data): ProfileSelection
    {
        $unknown = array_diff(array_map(strval(...), array_keys($data)), self::KEYS);
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(\sprintf('Unknown profile field(s): %s. Known: %s.', implode(', ', $unknown), implode(', ', self::KEYS)));
        }

        return new ProfileSelection(
            configuration: self::string($data, 'configuration'),
            cathlab: self::bool($data, 'cathlab'),
            neurosurgery: self::bool($data, 'neurosurgery'),
            strokeUnit: self::enum($data, 'stroke_unit', StrokeUnitLevel::class),
            traumaLevel: self::enum($data, 'trauma_level', TraumaLevel::class),
            obstetrics: self::enum($data, 'obstetrics', ObstetricsLevel::class),
            paediatrics: self::bool($data, 'paediatrics'),
            tier: self::enum($data, 'tier', HospitalTier::class),
            size: self::enum($data, 'size', HospitalSize::class),
            beds: self::int($data, 'beds'),
            annualVolume: self::int($data, 'annual_volume'),
            closureShare: self::float($data, 'closure_share'),
        );
    }

    /**
     * @param array<mixed> $data
     */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if (null !== $value && !\is_string($value)) {
            throw new \InvalidArgumentException(\sprintf('The profile field "%s" must be a string.', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function bool(array $data, string $key): ?bool
    {
        $value = $data[$key] ?? null;
        if (null !== $value && !\is_bool($value)) {
            throw new \InvalidArgumentException(\sprintf('The profile field "%s" must be true or false.', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if (null !== $value && !\is_int($value)) {
            throw new \InvalidArgumentException(\sprintf('The profile field "%s" must be a whole number.', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function float(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;
        if (null !== $value && !\is_int($value) && !\is_float($value)) {
            throw new \InvalidArgumentException(\sprintf('The profile field "%s" must be a number.', $key));
        }

        return null === $value ? null : (float) $value;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param array<mixed>    $data
     * @param class-string<T> $enum
     *
     * @return T|null
     */
    private static function enum(array $data, string $key, string $enum): ?\BackedEnum
    {
        $value = $data[$key] ?? null;
        if (null === $value) {
            return null;
        }
        $case = \is_string($value) ? $enum::tryFrom($value) : null;
        if (null === $case) {
            throw new \InvalidArgumentException(\sprintf('The profile field "%s" takes one of: %s.', $key, implode(', ', array_map(static fn (\BackedEnum $c): string => (string) $c->value, $enum::cases()))));
        }

        return $case;
    }
}
