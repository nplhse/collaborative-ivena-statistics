<?php

declare(strict_types=1);

namespace App\Import\Domain\Enum;

enum ImportSourceFileStatus: string
{
    case Ready = 'ready';
    case EmptyPath = 'empty_path';
    case OutsideBase = 'outside_base';
    case NotFound = 'not_found';
    case Unreadable = 'unreadable';
    case EmptyFile = 'empty_file';

    public function isReady(): bool
    {
        return self::Ready === $this;
    }
}
