<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Run;

/**
 * Which hospitals a generation run covers (#877): one hospital, an explicit selection, or all participating ones.
 */
enum GenerationRunScope: string
{
    case One = 'one';
    case Selected = 'selected';
    case AllParticipating = 'all';
}
