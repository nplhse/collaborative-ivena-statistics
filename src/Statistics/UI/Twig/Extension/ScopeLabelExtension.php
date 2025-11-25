<?php

namespace App\Statistics\UI\Twig\Extension;

use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Util\ScopeLabelFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/** @psalm-suppress UnusedClass */
final class ScopeLabelExtension extends AbstractExtension
{
    public function __construct(
        private ScopeLabelFormatter $formatter,
    ) {
    }

    #[\Override]
    public function getFilters(): array
    {
        return [new TwigFilter('scope_label', fn (Scope $s) => $this->formatter->format($s))];
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('scope_label', fn (Scope $s) => $this->formatter->format($s))];
    }
}
