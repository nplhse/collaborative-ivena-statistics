<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\Enum;

use App\Statistics\GenericAnalysis\UI\Http\Navigation\GenericAnalysisQueryKeys;
use Symfony\Component\HttpFoundation\Request;

enum GenericAnalysisTableRowLimit: string
{
    case Top5 = '5';
    case Top10 = '10';
    case All = 'all';

    public function cap(): ?int
    {
        return match ($this) {
            self::Top5 => 5,
            self::Top10 => 10,
            self::All => null,
        };
    }

    public static function fromRequestValue(string $value): self
    {
        return match ($value) {
            self::Top10->value => self::Top10,
            self::All->value => self::All,
            default => self::Top5,
        };
    }

    public static function resolve(
        Request $request,
        int $distinctPrimaryBuckets,
        bool $primaryIsTemporal,
        bool $preserveAllBuckets = false,
    ): self {
        if ($request->query->has(GenericAnalysisQueryKeys::TOP)) {
            return self::fromRequestValue($request->query->getString(GenericAnalysisQueryKeys::TOP));
        }

        if ($preserveAllBuckets || $primaryIsTemporal || $distinctPrimaryBuckets <= 5) {
            return self::All;
        }

        return self::Top5;
    }
}
