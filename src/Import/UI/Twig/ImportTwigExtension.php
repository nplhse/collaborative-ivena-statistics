<?php

declare(strict_types=1);

namespace App\Import\UI\Twig;

use App\Import\Domain\Service\ImportDuplicationRatePresentation;
use App\Import\Domain\Service\ImportRejectionRatePresentation;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ImportTwigExtension extends AbstractExtension
{
    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('import_rate_badge', $this->importRateBadge(...)),
        ];
    }

    /**
     * @return array{color: string, icon: string}
     */
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
