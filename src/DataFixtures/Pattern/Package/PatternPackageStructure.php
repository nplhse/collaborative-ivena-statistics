<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Package;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The structure of a pattern package as Validator constraints on the decoded JSON (ADR 022).
 *
 * Tables are keyed by data (segment names, PZC codes, department names), so the structure is checked
 * on the array before the package DTOs are built: known keys only, every required key, numbers where
 * numbers belong, shares and probabilities between 0 and 1.
 */
final class PatternPackageStructure
{
    public static function constraint(): Assert\Collection
    {
        return new Assert\Collection(
            fields: [
                'meta' => new Assert\Collection(
                    fields: [
                        'format' => self::required(new Assert\Type('string')),
                        'version' => self::required(new Assert\Type('int')),
                        'created_at' => self::required(new Assert\Type('string'), new Assert\DateTime(format: \DateTimeInterface::ATOM)),
                        'source' => self::required(new Assert\Type('string'), new Assert\Regex(pattern: '/^[a-z0-9-]{1,64}$/', message: 'Expected lowercase letters, digits and dashes (at most 64).')),
                        'app_version' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 255)]),
                        'catalog' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 255)]),
                        'counts' => self::required(new Assert\Type('array'), new Assert\All([new Assert\NotNull(), new Assert\Type('int'), new Assert\PositiveOrZero()])),
                        'checksum' => new Assert\Collection(
                            fields: [
                                'algorithm' => self::required(new Assert\EqualTo(PatternPackageFormat::CHECKSUM_ALGORITHM)),
                                'value' => self::required(new Assert\Type('string'), new Assert\Regex(pattern: '/^[0-9a-f]{64}$/', message: 'Expected a lowercase SHA-256 hex digest.')),
                            ],
                        ),
                        'notes' => new Assert\Optional([new Assert\Type('string')]),
                    ],
                ),
                'segments' => self::required(new Assert\Type('array'), new Assert\Count(min: 1), new Assert\All([self::segment()])),
                'indication_groups' => self::required(new Assert\Type('array'), new Assert\Count(min: 1), new Assert\All([self::indicationGroup()])),
                'fallback_group' => self::required(new Assert\Type('string')),
            ],
        );
    }

    private static function segment(): Assert\Collection
    {
        return new Assert\Collection(
            fields: [
                'indications' => self::optionalShares(),
                'occasions' => self::shares(),
                'assignments' => self::shares(),
                'infections' => self::optionalShares(),
                'secondary_transports' => self::optionalShares(),
                'transport_types' => self::shares(),
                'hours' => self::shares(),
                'flags' => self::probabilities(),
                'transport_time_minutes' => new Assert\Collection(
                    fields: array_fill_keys(PatternPackageFormat::PERCENTILES, self::required(new Assert\Type(['int', 'float']), new Assert\Positive())),
                ),
            ],
        );
    }

    private static function indicationGroup(): Assert\Collection
    {
        return new Assert\Collection(
            fields: [
                'departments' => self::shares(),
                'urgency' => self::shares(),
                'ages' => self::shares(),
                'female' => self::share(),
                'resus_factor' => self::required(new Assert\Type(['int', 'float']), new Assert\Range(min: 0, max: 100)),
                'flags' => self::probabilities(),
            ],
        );
    }

    private static function optionalShares(): Assert\Collection
    {
        return new Assert\Collection(fields: ['present' => self::share(), 'shares' => self::shares()]);
    }

    /**
     * A table of shares with at least one entry.
     */
    private static function shares(): Assert\Required
    {
        return self::required(new Assert\Type('array'), new Assert\Count(min: 1), new Assert\All(self::share()->constraints));
    }

    /**
     * A table of probabilities that may be empty.
     */
    private static function probabilities(): Assert\Required
    {
        return self::required(new Assert\Type('array'), new Assert\All(self::share()->constraints));
    }

    private static function share(): Assert\Required
    {
        return self::required(new Assert\Type(['int', 'float']), new Assert\Range(min: 0, max: 1));
    }

    private static function required(Constraint ...$constraints): Assert\Required
    {
        return new Assert\Required([new Assert\NotNull(), ...$constraints]);
    }
}
