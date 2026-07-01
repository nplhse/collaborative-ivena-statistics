<?php

declare(strict_types=1);

namespace App\Engagement\UI\Console\Input;

use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Validator\Constraints as Assert;

final class MonthlyReminderPreviewInput
{
    #[Option(description: 'Hospital ID', name: 'hospital-id')]
    #[Assert\Positive]
    public ?int $hospitalId = null;

    #[Option(description: 'Send the email to the hospital owner')]
    public bool $send = false;

    #[Option(description: 'Send even if the owner opted out (like admin action)', name: 'ignore-opt-out')]
    public bool $ignoreOptOut = false;

    #[Option(description: 'Write HTML to file path')]
    public ?string $output = null;

    #[Option(description: 'Reference date (Y-m-d) for period calculation')]
    public ?\DateTimeImmutable $date = null;
}
