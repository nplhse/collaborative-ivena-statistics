<?php

declare(strict_types=1);

namespace App\User\Application\Contract;

use App\User\Application\Activity\UserActivityBackfillCommentRecord;
use App\User\Application\Activity\UserActivityBackfillPostRecord;

interface UserActivityBackfillContentSourceInterface
{
    /**
     * @return list<UserActivityBackfillPostRecord>
     */
    public function publishedPosts(): array;

    /**
     * @return list<UserActivityBackfillCommentRecord>
     */
    public function commentsOnPublishedPosts(): array;
}
