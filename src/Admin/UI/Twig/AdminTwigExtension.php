<?php

declare(strict_types=1);

namespace App\Admin\UI\Twig;

use App\Storage\Application\ByteFormatter;

final class AdminTwigExtension
{
    #[\Twig\Attribute\AsTwigFilter(name: 'format_bytes')]
    public function formatBytes(int $bytes): string
    {
        return ByteFormatter::format($bytes);
    }

    /**
     * A size change with its sign, e.g. "+1.2 MB" or "−300 B".
     */
    #[\Twig\Attribute\AsTwigFilter(name: 'format_bytes_delta')]
    public function formatBytesDelta(int $bytes): string
    {
        return match (true) {
            $bytes > 0 => '+'.ByteFormatter::format($bytes),
            $bytes < 0 => '−'.ByteFormatter::format(-$bytes),
            default => '±0 B',
        };
    }
}
