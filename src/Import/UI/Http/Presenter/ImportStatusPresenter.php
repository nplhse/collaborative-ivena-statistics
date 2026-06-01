<?php

declare(strict_types=1);

namespace App\Import\UI\Http\Presenter;

use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ImportStatusPresenter
{
    /** @var array<string, array{icon: string, tone: string}> */
    private const array VISUALS = [
        'pending' => ['icon' => 'tabler:clock', 'tone' => 'secondary'],
        'running' => ['icon' => 'tabler:loader-2', 'tone' => 'lime'],
        'completed' => ['icon' => 'tabler:circle-check', 'tone' => 'green'],
        'failed' => ['icon' => 'tabler:alert-triangle', 'tone' => 'red'],
        'cancelled' => ['icon' => 'tabler:circle-x', 'tone' => 'orange'],
        'partial' => ['icon' => 'tabler:circle-half', 'tone' => 'yellow'],
    ];

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array{
     *     id: int,
     *     status: string,
     *     label: string,
     *     message: string,
     *     progress: int|null,
     *     isFinal: bool,
     *     detailUrl: string,
     *     icon: string,
     *     iconTone: string,
     *     stepsModifier: string,
     *     steps: list<array{key: string, label: string, description: string, state: string}>,
     * }
     */
    public function present(Import $import): array
    {
        $id = $import->getId();
        if (null === $id) {
            throw new \InvalidArgumentException('Import must be persisted.');
        }

        $status = $import->getStatus() ?? ImportStatus::PENDING;
        $statusKey = strtolower($status->name);
        $visuals = self::VISUALS[$statusKey] ?? self::VISUALS['pending'];

        return [
            'id' => $id,
            'status' => $statusKey,
            'label' => $this->translator->trans('import.status.label.'.$statusKey),
            'message' => $this->translator->trans('import.status.message.'.$statusKey),
            'progress' => $this->resolveProgress($import, $status),
            'isFinal' => $import->isFinalStatus(),
            'detailUrl' => $this->urlGenerator->generate('app_import_show', ['id' => $id]),
            'icon' => $visuals['icon'],
            'iconTone' => $visuals['tone'],
            'stepsModifier' => $this->resolveStepsModifier($status),
            'steps' => $this->buildSteps($status),
        ];
    }

    /**
     * @return list<array{key: string, label: string, description: string, state: string}>
     */
    private function buildSteps(ImportStatus $status): array
    {
        $uploaded = [
            'key' => 'uploaded',
            'label' => $this->translator->trans('import.processing.step.uploaded'),
            'description' => $this->translator->trans('import.processing.step.uploaded_desc'),
            'state' => 'done',
        ];

        $processingState = match ($status) {
            ImportStatus::PENDING, ImportStatus::RUNNING => 'active',
            default => 'done',
        };

        $processing = [
            'key' => 'processing',
            'label' => $this->translator->trans('import.processing.step.processing'),
            'description' => $this->translator->trans('import.processing.step.processing_desc'),
            'state' => $processingState,
        ];

        $resultState = $status->isFinal() ? 'active' : 'pending';

        $result = [
            'key' => 'result',
            'label' => $this->resolveResultLabel($status),
            'description' => $this->translator->trans('import.status.message.'.strtolower($status->name)),
            'state' => $resultState,
        ];

        return [$uploaded, $processing, $result];
    }

    private function resolveResultLabel(ImportStatus $status): string
    {
        return match ($status) {
            ImportStatus::COMPLETED => $this->translator->trans('import.processing.step.result.completed'),
            ImportStatus::PARTIAL => $this->translator->trans('import.processing.step.result.partial'),
            ImportStatus::FAILED => $this->translator->trans('import.processing.step.result.failed'),
            ImportStatus::CANCELLED => $this->translator->trans('import.processing.step.result.cancelled'),
            default => $this->translator->trans('import.processing.step.result'),
        };
    }

    private function resolveStepsModifier(ImportStatus $status): string
    {
        return match ($status) {
            ImportStatus::FAILED, ImportStatus::CANCELLED => 'red',
            ImportStatus::PARTIAL => 'yellow',
            default => 'green',
        };
    }

    private function resolveProgress(Import $import, ImportStatus $status): ?int
    {
        if (ImportStatus::RUNNING !== $status) {
            return null;
        }

        $rowCount = $import->getRowCount();
        $rowsPassed = $import->getRowsPassed();
        if (null === $rowCount || $rowCount <= 0 || null === $rowsPassed) {
            return null;
        }

        $percentage = (int) round((float) ($rowsPassed * 100) / (float) $rowCount);

        return min(100, max(0, $percentage));
    }
}
