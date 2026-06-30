<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mail;

use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackCategory;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;

interface TransactionalMailer
{
    /**
     * @param array<string, mixed> $expiresAtMessageData
     */
    public function sendVerificationEmail(
        string $recipientEmail,
        string $signedUrl,
        string $expiresAtMessageKey,
        array $expiresAtMessageData,
        string $homepageUrl,
        string $locale,
    ): void;

    public function sendPasswordResetEmail(
        string $recipientEmail,
        ResetPasswordToken $resetToken,
        string $locale,
    ): void;

    public function sendAdminFeedbackEmail(
        Feedback $feedback,
        FeedbackCategory $category,
        string $contextJsonPreview,
    ): void;
}
