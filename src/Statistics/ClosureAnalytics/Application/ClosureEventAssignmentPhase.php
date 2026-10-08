<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application;

use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceConfig;

enum ClosureEventAssignmentPhase: string
{
    case Before = 'before';
    case During = 'during';
    case After = 'after';

    public static function fromRequest(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::During;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public function bounds(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): array
    {
        $hours = ClosureVolumeReferenceConfig::CONTEXT_HOURS;

        return match ($this) {
            self::Before => [$startsAt->modify(sprintf('-%d hours', $hours)), $startsAt],
            self::During => [$startsAt, $endsAt],
            self::After => [$endsAt, $endsAt->modify(sprintf('+%d hours', $hours))],
        };
    }
}
