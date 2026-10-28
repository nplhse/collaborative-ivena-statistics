<?php

declare(strict_types=1);

namespace App\DataFixtures\Structure\Dto;

/**
 * What an indication can require of a hospital (capability gate).
 *
 * Levels are minimums: `trauma_regional` is met by a regional and a supraregional trauma centre.
 */
enum Capability: string
{
    case CATHLAB = 'cathlab';
    case NEUROSURGERY = 'neurosurgery';
    case STROKE_UNIT = 'stroke_unit';
    case PAEDIATRICS = 'paediatrics';
    case TRAUMA_REGIONAL = 'trauma_regional';
    case TRAUMA_SUPRA = 'trauma_supra';
    case OBSTETRICS_BASIC = 'obstetrics_basic';
    case OBSTETRICS_PERINATAL = 'obstetrics_perinatal';
}
