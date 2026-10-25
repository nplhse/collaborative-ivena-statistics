<?php

declare(strict_types=1);

namespace App\Storage\Application;

/**
 * Human-readable sizes with binary steps (1 KB = 1024 B), as on the Admin dashboard.
 */
final class ByteFormatter
{
    public static function format(int $bytes): string
    {
        if (abs($bytes) < 1024) {
            return $bytes.' B';
        }

        $value = (float) $bytes / 1024.0;
        $unit = 'KB';
        foreach (['MB', 'GB', 'TB'] as $larger) {
            if (abs($value) < 1024.0) {
                break;
            }

            $value /= 1024.0;
            $unit = $larger;
        }

        return sprintf(\in_array($unit, ['GB', 'TB'], true) ? '%.2f %s' : '%.1f %s', $value, $unit);
    }
}
