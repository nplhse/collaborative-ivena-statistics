<?php

namespace App\Allocation\Infrastructure\Factory;

use App\Allocation\Domain\Entity\Assessment;
use App\Allocation\Domain\Enum\AssessmentAirway;
use App\Allocation\Domain\Enum\AssessmentBreathing;
use App\Allocation\Domain\Enum\AssessmentCirculation;
use App\Allocation\Domain\Enum\AssessmentDisability;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Assessment>
 */
final class AssessmentFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return Assessment::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'airway' => self::faker()->randomElement(AssessmentAirway::cases()),
            'breathing' => self::faker()->randomElement(AssessmentBreathing::cases()),
            'circulation' => self::faker()->randomElement(AssessmentCirculation::cases()),
            'disability' => self::faker()->randomElement(AssessmentDisability::cases()),
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTime()),
        ];
    }

    #[\Override]
    protected function initialize(): static
    {
        return $this
            // ->afterInstantiate(function(Assessment $assessment): void {})
        ;
    }
}
