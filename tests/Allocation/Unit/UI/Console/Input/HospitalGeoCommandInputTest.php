<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\UI\Console\Input;

use App\Allocation\Application\Hospital\HospitalGeoScopeType;
use App\Allocation\UI\Console\Input\HospitalGeoCommandInput;
use PHPUnit\Framework\TestCase;

final class HospitalGeoCommandInputTest extends TestCase
{
    public function testRequiresExactlyOneScopeOption(): void
    {
        $input = new HospitalGeoCommandInput();
        self::assertNotNull($input->scopeError());

        $input->hospitalId = 1;
        $input->stateId = 2;
        self::assertNotNull($input->scopeError());

        $input->stateId = null;
        self::assertNull($input->scopeError());
        self::assertSame(HospitalGeoScopeType::Hospital, $input->toScope()->type);
        self::assertFalse($input->ignoresParticipatingOnly());

        $input->participatingOnly = true;
        self::assertTrue($input->ignoresParticipatingOnly());
    }

    public function testToScopeMapsDispatchAreaAndState(): void
    {
        $area = new HospitalGeoCommandInput();
        $area->dispatchAreaId = 7;
        $area->participatingOnly = true;

        self::assertNull($area->scopeError());
        self::assertFalse($area->ignoresParticipatingOnly());
        $scope = $area->toScope();
        self::assertSame(HospitalGeoScopeType::DispatchArea, $scope->type);
        self::assertSame(7, $scope->id);
        self::assertTrue($scope->participatingOnly);

        $state = new HospitalGeoCommandInput();
        $state->stateId = 3;
        $stateScope = $state->toScope();
        self::assertSame(HospitalGeoScopeType::State, $stateScope->type);
        self::assertSame(3, $stateScope->id);
        self::assertFalse($stateScope->participatingOnly);
    }

    public function testToScopeThrowsWhenScopeWasNotValidated(): void
    {
        $this->expectException(\LogicException::class);

        new HospitalGeoCommandInput()->toScope();
    }
}
