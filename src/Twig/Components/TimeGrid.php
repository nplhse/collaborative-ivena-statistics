<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Enum\TimeGridMode;
use App\Model\TimeGridCell;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'TimeGrid')]
final class TimeGrid
{
    /** @var list<array{label:string,periodKey:string,isTotal?:bool}> */
    public array $columns = [];

    /** @var list<array{label:string,format:'int'|'pct',cells:list<TimeGridCell>}> */
    public array $rows = [];

    public TimeGridMode $mode = TimeGridMode::RAW;
}
