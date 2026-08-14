<?php

declare(strict_types=1);

namespace App\User\Application\Contract;

use App\User\Application\Activity\UserActivityBackfillImportRecord;

interface UserActivityBackfillImportSourceInterface
{
    /**
     * Successful imports (COMPLETED|PARTIAL), oldest first.
     *
     * @return list<UserActivityBackfillImportRecord>
     */
    public function successfulImports(): array;
}
