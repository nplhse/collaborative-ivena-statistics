<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\ClosureAnalytics\Application\ClosureEventAssignmentPopulation;
use PHPUnit\Framework\TestCase;

final class ClosureEventAssignmentPopulationTest extends TestCase
{
    public function testFromRequestDefaultsToClosed(): void
    {
        self::assertSame(ClosureEventAssignmentPopulation::Closed, ClosureEventAssignmentPopulation::fromRequest(null));
        self::assertSame(ClosureEventAssignmentPopulation::Closed, ClosureEventAssignmentPopulation::fromRequest(''));
    }

    public function testFromRequestAcceptsExplicitValues(): void
    {
        self::assertSame(ClosureEventAssignmentPopulation::Speciality, ClosureEventAssignmentPopulation::fromRequest('speciality'));
        self::assertSame(ClosureEventAssignmentPopulation::Closed, ClosureEventAssignmentPopulation::fromRequest('closed'));
    }
}
