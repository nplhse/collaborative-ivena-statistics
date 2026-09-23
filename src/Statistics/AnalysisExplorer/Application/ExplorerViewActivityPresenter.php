<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Shared\Application\DateTime\RelativeTimestampFormatter;
use App\Statistics\Domain\Entity\SavedExplorerView;

final readonly class ExplorerViewActivityPresenter
{
    public function __construct(
        private RelativeTimestampFormatter $formatter,
    ) {
    }

    /**
     * Last save when the view changed after creation, otherwise the creation time.
     *
     * @return array{kind: 'created'|'updated', relativeLabel: string, absoluteLabel: string, iso8601: string}
     */
    public function present(SavedExplorerView $view): array
    {
        $createdAt = $view->getCreatedAt();
        $updatedAt = $view->getUpdatedAt();
        $wasUpdated = $updatedAt->getTimestamp() > $createdAt->getTimestamp();
        $formatted = $this->formatter->format($wasUpdated ? $updatedAt : $createdAt);

        return [
            'kind' => $wasUpdated ? 'updated' : 'created',
            'relativeLabel' => $formatted->relativeLabel,
            'absoluteLabel' => $formatted->absoluteLabel,
            'iso8601' => $formatted->iso8601,
        ];
    }
}
