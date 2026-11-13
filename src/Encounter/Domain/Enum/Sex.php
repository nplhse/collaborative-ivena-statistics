<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Enum;

/**
 * `sex` of the `ed_visit` exchange format (#1047).
 */
enum Sex: string
{
    case Male = 'M';
    case Female = 'F';
    case Diverse = 'X';
}
