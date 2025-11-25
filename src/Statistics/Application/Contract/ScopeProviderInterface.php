<?php

declare(strict_types=1);

namespace App\Statistics\Application\Contract;

use App\Statistics\Domain\Model\Scope;

interface ScopeProviderInterface
{
    /**
     * @return iterable<Scope>
     */
    public function provideForImport(int $importId): iterable;
}
