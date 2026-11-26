<?php

declare(strict_types=1);

namespace App\Import\Application\Contracts;

use App\Import\Domain\Enum\ImportType;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Workflow dispatcher of an import type that another context processes (extension point
 * `import.workflow_dispatcher`). `ImportStartDispatcher` starts runs of that type through it, so reprocessing
 * and `app:import:start` work without a dependency from `Import` on the processing context.
 */
#[AutoconfigureTag(self::TAG)]
interface ImportTypeWorkflowDispatcherInterface extends ImportWorkflowDispatcherInterface
{
    public const string TAG = 'import.workflow_dispatcher';

    /**
     * The import type this dispatcher starts runs for.
     */
    public static function importType(): ImportType;
}
