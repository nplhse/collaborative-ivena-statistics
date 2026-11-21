<?php

declare(strict_types=1);

namespace App\Content\Domain\Enum;

/**
 * Who may receive the file of a media item (ADR 039). Every media item is public until #580 decides who may see
 * restricted media.
 */
enum MediaVisibility: string
{
    case Public = 'public';
    case Restricted = 'restricted';
}
