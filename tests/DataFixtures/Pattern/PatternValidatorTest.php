<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Pattern;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\DataFixtures\Pattern\Application\PatternValidator;
use App\DataFixtures\Pattern\Dto\DistributionPattern;
use App\DataFixtures\Pattern\Dto\PatternSegment;
use App\DataFixtures\Pattern\Infrastructure\PatternYamlPaths;
use App\DataFixtures\Pattern\Infrastructure\PatternYamlSerializer;
use App\DataFixtures\Pattern\Infrastructure\Sampling\WeightedCategoricalSampler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PatternValidatorTest extends TestCase
{
    #[Test]
    public function validatePatternAcceptsBalancedDistributions(): void
    {
        $validator = new PatternValidator(
            new PatternYamlSerializer(new PatternYamlPaths(dirname(__DIR__, 3))),
            new WeightedCategoricalSampler(),
        );

        $pattern = new DistributionPattern(
            name: 'urban-full',
            version: 1,
            schema: DistributionPattern::SCHEMA,
            segment: new PatternSegment(HospitalTier::FULL, HospitalLocation::URBAN),
            meta: ['sample_size' => 5000],
            distributions: [
                'urgency' => ['1' => 0.5, '2' => 0.3, '3' => 0.2],
                'gender' => ['M' => 0.5, 'F' => 0.5],
            ],
        );

        self::assertSame([], $validator->validatePattern($pattern, 100));
    }

    #[Test]
    public function validatePatternRejectsUnbalancedDistribution(): void
    {
        $validator = new PatternValidator(
            new PatternYamlSerializer(new PatternYamlPaths(dirname(__DIR__, 3))),
            new WeightedCategoricalSampler(),
        );

        $pattern = new DistributionPattern(
            name: 'urban-full',
            version: 1,
            schema: DistributionPattern::SCHEMA,
            segment: new PatternSegment(HospitalTier::FULL, HospitalLocation::URBAN),
            meta: ['sample_size' => 5000],
            distributions: [
                'urgency' => ['1' => 0.9, '2' => 0.05, '3' => 0.01],
            ],
        );

        $errors = $validator->validatePattern($pattern, 100);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('urgency', $errors[0]);
    }
}
