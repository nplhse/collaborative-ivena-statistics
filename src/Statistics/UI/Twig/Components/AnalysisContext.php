<?php

declare(strict_types=1);

namespace App\Statistics\UI\Twig\Components;

use App\Statistics\UI\Http\Controller\AnalysisContextViewModel;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'Statistics:AnalysisContext', template: '@Statistics/components/AnalysisContext.html.twig')]
final class AnalysisContext
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public AnalysisContextViewModel $context;
}
