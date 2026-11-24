<?php

declare(strict_types=1);

namespace App\Content\UI\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * "Help" button for a page header of the application (#1590): links the help article with this help key. Renders
 * nothing when the article is missing or hidden from the viewer (`help_link()`).
 */
#[AsTwigComponent(name: 'Content:HelpLink', template: '@Content/components/HelpLink.html.twig')]
final class HelpLink
{
    /** Help key of the article, e.g. `import.status`. */
    public string $key = '';
}
