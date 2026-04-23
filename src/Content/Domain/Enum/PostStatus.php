<?php

declare(strict_types=1);

namespace App\Content\Domain\Enum;

enum PostStatus: string
{
    case DRAFT = 'Draft';
    case PUBLISHED = 'Published';
}
