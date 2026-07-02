<?php

declare(strict_types=1);

namespace App\Admin\UI\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class AdminTwigExtension extends AbstractExtension
{
    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('format_bytes', $this->formatBytes(...)),
        ];
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }

        if ($bytes < 1024 * 1024 * 1024) {
            return sprintf('%.1f MB', $bytes / (1024 * 1024));
        }

        return sprintf('%.2f GB', $bytes / (1024 * 1024 * 1024));
    }
}
