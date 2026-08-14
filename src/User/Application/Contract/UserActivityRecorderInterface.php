<?php

declare(strict_types=1);

namespace App\User\Application\Contract;

use App\User\Application\Activity\UserActivityWrite;

interface UserActivityRecorderInterface
{
    /**
     * Persists the activity when the deduplication key is new.
     *
     * @return bool true when a row was inserted, false when the key already existed
     *
     * @psalm-suppress PossiblyUnusedReturnValue Production callers ignore the result; tests assert it.
     */
    public function record(UserActivityWrite $write): bool;
}
