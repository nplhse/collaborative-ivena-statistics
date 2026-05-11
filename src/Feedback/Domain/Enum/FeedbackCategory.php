<?php

declare(strict_types=1);

namespace App\Feedback\Domain\Enum;

enum FeedbackCategory: string
{
    case BUG = 'bug';
    case IMPROVEMENT = 'improvement';
    case QUESTION = 'question';
    case OTHER = 'other';
}
