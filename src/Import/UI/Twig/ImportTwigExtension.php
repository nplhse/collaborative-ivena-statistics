<?php

declare(strict_types=1);

namespace App\Import\UI\Twig;

use App\Import\Domain\Service\ImportDuplicationRatePresentation;
use App\Import\Domain\Service\ImportRejectionRatePresentation;

final class ImportTwigExtension
{
    /**
     * @return array{color: string, icon: string}
     */
    #[\Twig\Attribute\AsTwigFunction(name: 'import_rate_badge')]
    public function importRateBadge(float $percent, string $kind): array
    {
        $badge = match ($kind) {
            'rejection' => ImportRejectionRatePresentation::forPercent($percent),
            'duplication' => ImportDuplicationRatePresentation::forPercent($percent),
            default => throw new \InvalidArgumentException(sprintf('Unknown import rate kind "%s".', $kind)),
        };

        return [
            'color' => $badge->color,
            'icon' => $badge->icon,
        ];
    }
}
