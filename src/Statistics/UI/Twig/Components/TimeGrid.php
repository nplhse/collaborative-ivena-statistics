<?php

declare(strict_types=1);

namespace App\Statistics\UI\Twig\Components;

use App\Statistics\Domain\Enum\TimeGridMode;
use App\Statistics\Domain\Model\TimeGridCell;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'TimeGrid', template: '@Statistics/components/TimeGrid.html.twig')]
final class TimeGrid
{
    /** @var list<array{label:string,periodKey:string,isTotal?:bool}> */
    public array $columns = [];

    /** @var list<array{label:string,format:'int'|'pct',cells:list<TimeGridCell>}> */
    public array $rows = [];

    public TimeGridMode $mode = TimeGridMode::RAW;
}
