<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Domain\Enum;

/**
 * `admission_type` of the `ed_visit` exchange format (#1047).
 */
enum AdmissionType: string
{
    case Emergency = 'emergency';
    case Elective = 'elective';
}
