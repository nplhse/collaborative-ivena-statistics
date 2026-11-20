<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Tool;

/**
 * What a tool is about, independent of its category (#1201): the Tools landing page shows the topics as
 * badges and filters by one of them. Fixed here, like ToolCategory, so labels stay consistent; the topic
 * filter lists them in the order of the cases.
 */
enum ToolTopic: string
{
    case Allocations = 'allocations';
    case Encounters = 'encounters';
    case Indications = 'indications';
    case Imports = 'imports';

    public function labelKey(): string
    {
        return 'tools.topic.'.$this->value;
    }
}
