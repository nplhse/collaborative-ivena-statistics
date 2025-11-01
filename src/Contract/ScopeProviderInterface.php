<?php

declare(strict_types=1);

namespace App\Contract;

use App\Model\Scope;

interface ScopeProviderInterface
{
    /**
     * @return iterable<Scope>
     */
    public function provideForImport(int $importId): iterable;
}
