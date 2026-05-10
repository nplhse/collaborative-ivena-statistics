<?php

declare(strict_types=1);

namespace App\Feedback\Domain\Enum;

enum FeedbackStatus: string
{
    case NEW = 'new';
    case DONE = 'done';
}
