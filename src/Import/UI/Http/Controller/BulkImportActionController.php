<?php

declare(strict_types=1);

namespace App\Import\UI\Http\Controller;

use App\Import\Application\DTO\ImportBulkActionResult;
use App\Import\Application\Service\ImportBulkReprocessService;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Security\Voter\ImportVoter;
use App\Shared\UI\Http\BulkSelection;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Bulk actions on the import list (#720). Every selected id is checked again with ImportVoter;
 * the selection on the page is not trusted.
 */
#[IsGranted('ROLE_PARTICIPANT')]
final class BulkImportActionController extends AbstractController
{
    public const string CSRF_TOKEN = 'import_bulk';

    public const string ACTION_REPROCESS = 'reprocess';

    public function __construct(
        private readonly ImportBulkReprocessService $reprocessService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/import/bulk', name: 'app_import_bulk', methods: ['POST'], priority: 1)]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $ids = BulkSelection::ids($request);
        if ([] === $ids) {
            $this->addFlash('warning', $this->trans('flash.import.bulk.none_selected'));

            return $this->redirectToRoute('app_import_index');
        }

        $action = $request->request->getString('bulk_action');
        $result = match ($action) {
            self::ACTION_REPROCESS => $this->reprocessService->reprocess(
                $ids,
                $user->getId(),
                fn (Import $import): bool => $this->isGranted(ImportVoter::DELETE, $import),
                fn (Import $import): bool => $this->isGranted(ImportVoter::REPROCESS, $import),
            ),
            default => throw new BadRequestHttpException(\sprintf('Unknown bulk action "%s".', $action)),
        };

        $this->report($result, 'flash.import.bulk.reprocess_queued');

        return null === $result->batchRunId
            ? $this->redirectToRoute('app_import_index')
            : $this->redirectToRoute('app_import_index', ['batch' => $result->batchRunId]);
    }

    private function report(ImportBulkActionResult $result, string $handledKey): void
    {
        if ($result->handled > 0) {
            $this->addFlash('success', $this->trans($handledKey, ['count' => $result->handled]));
        }

        $reasons = $result->skippedByReason();
        if ([] === $reasons) {
            return;
        }

        $parts = array_map(
            fn (array $entry): string => $this->trans('flash.import.bulk.reason.'.$entry['reason']->value, ['count' => $entry['count']]),
            $reasons,
        );
        $this->addFlash($result->handled > 0 ? 'warning' : 'error', $this->trans('flash.import.bulk.skipped', [
            'count' => $result->skippedCount(),
            'reasons' => implode(', ', $parts),
        ]));
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'import');
    }
}
