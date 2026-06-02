<?php

declare(strict_types=1);

namespace App\Content\Domain\Enum;

enum MediaType: string
{
    case IMAGE = 'image';
    case PDF = 'pdf';
    case DOCUMENT = 'document';
}
