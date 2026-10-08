<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

enum ClosureVolumeStratum: string
{
    case All = 'all';
    case Sk1 = 'sk1';
    case Sk2 = 'sk2';
    case Sk3 = 'sk3';
    case Resus = 'resus';
    case Cathlab = 'cathlab';

    public static function fromRequest(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::All;
    }

    public function storageStratum(): string
    {
        return match ($this) {
            self::Resus => 'resus',
            self::Cathlab => 'cathlab',
            default => 'base',
        };
    }

    public function urgencyCode(): ?int
    {
        return match ($this) {
            self::Sk1 => 1,
            self::Sk2 => 2,
            self::Sk3 => 3,
            default => null,
        };
    }

    /** @return list<self> */
    public static function choices(): array
    {
        return [self::All, self::Sk1, self::Sk2, self::Sk3, self::Resus, self::Cathlab];
    }
}
