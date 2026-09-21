<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Twig\DataTable;

final readonly class DataTableBadgePaletteStringable implements \Stringable
{
    public function __construct(private string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
