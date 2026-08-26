<?php

declare(strict_types=1);

namespace App\Admin\UI\Twig;

final class AdminTwigExtension
{
    #[\Twig\Attribute\AsTwigFilter(name: 'format_bytes')]
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
