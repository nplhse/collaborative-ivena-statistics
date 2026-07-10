<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\Domain\Entity\SavedExplorerView;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class SavedExplorerViewLabelResolver
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function title(SavedExplorerView $view): string
    {
        return $this->resolve($view, $view->getTitle());
    }

    public function description(SavedExplorerView $view): ?string
    {
        $raw = $view->getDescription();

        return null === $raw ? null : $this->resolve($view, $raw);
    }

    private function resolve(SavedExplorerView $view, string $value): string
    {
        if (!$view->isSystem()) {
            return $value;
        }

        return $this->translator->trans($value, [], 'statistics');
    }
}
