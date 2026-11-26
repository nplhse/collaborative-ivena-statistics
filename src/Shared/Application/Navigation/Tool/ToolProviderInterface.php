<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Tool;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Extension point for the Tools section (ADR 031).
 *
 * Contexts add their interactive workflows here without a dependency from Shared on them. Providers
 * check access themselves and return no tool when the current user may not open it. The registry is
 * asked on every page with the main navigation, so decide from roles and voters without database
 * queries.
 */
#[AutoconfigureTag(self::TAG)]
interface ToolProviderInterface
{
    public const string TAG = 'shared.tool';

    /**
     * @return list<Tool>
     */
    public function tools(): array;
}
