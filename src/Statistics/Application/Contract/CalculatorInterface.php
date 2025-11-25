<?php

declare(strict_types=1);

namespace App\Statistics\Application\Contract;

use App\Statistics\Domain\Model\Scope;

interface CalculatorInterface
{
    public function supports(Scope $scope): bool;

    public function calculate(Scope $scope): void;
}
