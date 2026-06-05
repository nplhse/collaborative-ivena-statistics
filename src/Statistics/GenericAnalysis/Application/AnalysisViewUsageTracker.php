<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\Domain\Entity\AnalysisViewUsage;
use App\Statistics\Domain\Entity\SavedAnalysisView;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewSource;
use App\Statistics\Infrastructure\Repository\AnalysisViewUsageRepository;
use App\Statistics\Infrastructure\Repository\SavedAnalysisViewRepository;
use App\User\Domain\Entity\User;

final readonly class AnalysisViewUsageTracker
{
    public function __construct(
        private AnalysisViewUsageRepository $usageRepository,
        private SavedAnalysisViewRepository $savedViewRepository,
    ) {
    }

    public function recordSystemViewOpen(User $user, string $systemViewKey): void
    {
        $usage = $this->usageRepository->findSystemUsage($user, $systemViewKey);
        if ($usage instanceof AnalysisViewUsage) {
            $usage->recordUse();
            $this->usageRepository->save($usage);

            return;
        }

        $this->usageRepository->save(new AnalysisViewUsage(
            user: $user,
            source: AnalysisViewSource::System,
            systemViewKey: $systemViewKey,
        ));
    }

    public function recordSavedViewOpen(User $user, SavedAnalysisView $savedView): void
    {
        $savedView->touchLastUsed();
        $this->savedViewRepository->save($savedView);

        $usage = $this->usageRepository->findSavedUsage($user, $savedView);
        if ($usage instanceof AnalysisViewUsage) {
            $usage->recordUse();
            $this->usageRepository->save($usage);

            return;
        }

        $this->usageRepository->save(new AnalysisViewUsage(
            user: $user,
            source: AnalysisViewSource::Saved,
            savedView: $savedView,
        ));
    }
}
