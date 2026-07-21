<?php

declare(strict_types=1);

namespace App\Feedback\Application\Contract;

use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackCategory;

interface AdminFeedbackNotifierInterface
{
    public function notify(
        Feedback $feedback,
        FeedbackCategory $category,
        string $contextJsonPreview,
    ): void;
}
