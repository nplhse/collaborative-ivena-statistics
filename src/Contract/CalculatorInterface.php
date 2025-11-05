<?php

declare(strict_types=1);

namespace App\Contract;

use App\Model\Scope;

interface CalculatorInterface
{
    public function supports(Scope $scope): bool;

    public function calculate(Scope $scope): void;
}
