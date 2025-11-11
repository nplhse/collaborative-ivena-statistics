<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'HospitalTimeGrid')]
final class HospitalTimeGrid
{
    /** @var list<array{label:string,periodKey:string,isTotal?:bool}> */
    public array $columns = [];

    /** @var list<array{label:string,values:list<int|float|null>,format:string}> */
    public array $rows = [];
}
