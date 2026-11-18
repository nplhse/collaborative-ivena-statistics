<?php

declare(strict_types=1);

namespace App\Encounter\Contract\KeyChange;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Figures a schema adds to the key change page (ADR 027, #1375): what a key change with "delete" removes of the
 * schema's own data beyond the encounters and imports the core counts, such as the IVENA links and review
 * decisions of the `ed_visit` match review. The core adds the figures of all schemas up; a schema without such
 * data contributes nothing.
 */
#[AutoconfigureTag(self::TAG)]
interface KeyChangeFiguresProviderInterface
{
    public const string TAG = 'encounter.key_change_figures';

    public function figuresForHospital(int $hospitalId): KeyChangeFigures;
}
