<?php

declare(strict_types=1);

namespace App\DataFixtures\Structure\Dto;

enum StrokeUnitLevel: string
{
    case NONE = 'none';
    case REGIONAL = 'regional';
    case SUPRA = 'supra';
}
