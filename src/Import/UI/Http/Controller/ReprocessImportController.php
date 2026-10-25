<?php

declare(strict_types=1);

namespace App\Import\UI\Http\Controller;

use App\Analytics\Application\UsageEvents\UsageAnalytics;
use App\Analytics\Domain\Enum\FeatureArea;
use App\Analytics\Domain\UsageEventName;
use App\Import\Application\Exception\DispatchException;
use App\Import\Application\Exception\ImportCreatorMissingException;
use App\Import\Application\Exception\ImportNotFoundException;
use App\Import\Application\Exception\ImportTypeMismatchException;
use App\Import\Application\Exception\UnsupportedImportTypeException;
use App\Import\Application\Service\ImportStartDispatcher;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Security\Voter\ImportVoter;
use App\User\Domain\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Queues a new run of an existing import from its stored source file (same workflow as `app:import:start`).
 */
#[IsGranted('ROLE_PARTICIPANT')]
final class ReprocessImportController extends AbstractController
{
    public function __construct(
        private readonly ImportStartDispatcher $dispatcher,
        private readonly UsageAnalytics $usageAnalytics,
        private readonly LoggerInterface $importLogger,
    ) {
    }

    #[Route('/import/{id}/reprocess', name: 'app_import_reprocess', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[IsGranted(ImportVoter::REPROCESS, subject: 'import')]
    public function __invoke(Import $import, Request $request): RedirectResponse
    {
        $importId = (int) $import->getId();

        if (!$this->isCsrfTokenValid('import_reprocess_'.$importId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (ImportStatus::RUNNING === $import->getStatus()) {
            $this->addFlash('error', new TranslatableMessage('flash.import.reprocess_running_blocked', domain: 'import'));

            return $this->redirectToRoute('app_import_show', ['id' => $importId]);
        }

        $user = $this->getUser();
        $userId = $user instanceof User ? $user->getId() : null;

        try {
            $this->dispatcher->dispatch($importId, $userId, ImportRunTrigger::Reprocess);
        } catch (ImportNotFoundException|ImportCreatorMissingException|ImportTypeMismatchException|UnsupportedImportTypeException|DispatchException $e) {
            $this->importLogger->error('import.reprocess.dispatch_failed', [
                'id' => $importId,
                'ex' => $e::class,
                'msg' => $e->getMessage(),
            ]);
            $this->addFlash('error', new TranslatableMessage('flash.import.reprocess_failed', domain: 'import'));

            return $this->redirectToRoute('app_import_show', ['id' => $importId]);
        }

        $this->usageAnalytics->record(UsageEventName::IMPORT_REPROCESSED, FeatureArea::Import);
        $this->addFlash('success', new TranslatableMessage('flash.import.reprocess_queued', domain: 'import'));

        return $this->redirectToRoute('app_import_processing', ['id' => $importId]);
    }
}
