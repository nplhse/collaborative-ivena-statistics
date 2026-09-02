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

    /**
     * Inserts the activity, or updates occurredAt and metadata when the key already exists.
     *
     * @return bool true when a row was inserted or updated
     *
     * @psalm-suppress PossiblyUnusedReturnValue Production callers ignore the result; tests assert it.
     */
    public function sync(UserActivityWrite $write): bool;
}
