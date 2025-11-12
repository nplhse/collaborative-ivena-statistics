<?php

namespace App\Twig\Extension;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class ArrayExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('values', fn($arr) => \is_array($arr) ? \array_values($arr) : $arr),
        ];
    }
}
