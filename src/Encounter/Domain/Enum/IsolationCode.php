<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Enum;

/**
 * One code of the `isolation` set of the `ed_visit` exchange format (#1047).
 */
enum IsolationCode: string
{
    case Gastroenteritis = 'gastroenteritis';
    case Clostridioides = 'clostridioides';
    case Covid19 = 'covid19';
    case Mdro = 'mdro';
    case Mrsa = 'mrsa';
    case Esbl = 'esbl';
    case Pseudomonas = 'pseudomonas';
    case Tuberculosis = 'tb';
    case Other = 'other';
}
