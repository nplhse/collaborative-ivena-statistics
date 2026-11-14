<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Page;

use App\Admin\UI\Form\PageContentDraftBuilder;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Infrastructure\Repository\PageTranslationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Autosave and discard of the block editor's draft (#967, ADR 036).
 *
 * Every request carries the draft revision it is based on. The server refuses older revisions with 409, so a late
 * autosave can neither overwrite newer input nor bring back a draft that was discarded or saved in the meantime.
 */
#[IsGranted('ROLE_ADMIN')]
final class PageTranslationDraftController extends AbstractController
{
    public const string CSRF_TOKEN_PREFIX = 'page_translation_draft_';

    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires controllers */
    public function __construct(
        private readonly PageTranslationRepository $translationRepository,
        private readonly PageContentDraftBuilder $draftBuilder,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/admin/page-translation/{id}/draft', name: 'app_admin_page_translation_draft_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function save(int $id, Request $request): JsonResponse
    {
        $translation = $this->loadTranslation($id, $request);
        if ($translation instanceof JsonResponse) {
            return $translation;
        }

        $submitted = $request->request->all('PageTranslation')['content'] ?? [];
        $translation->saveDraft($this->draftBuilder->build(is_array($submitted) ? $submitted : []));
        $this->entityManager->flush();

        return new JsonResponse([
            'revision' => $translation->getDraftRevision(),
            'savedAt' => $translation->getDraftUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/admin/page-translation/{id}/draft/discard', name: 'app_admin_page_translation_draft_discard', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function discard(int $id, Request $request): JsonResponse
    {
        $translation = $this->loadTranslation($id, $request);
        if ($translation instanceof JsonResponse) {
            return $translation;
        }

        $translation->discardDraft();
        $this->entityManager->flush();

        return new JsonResponse(['revision' => $translation->getDraftRevision()]);
    }

    /**
     * Loads the translation and checks the CSRF token and the revision the request is based on.
     */
    private function loadTranslation(int $id, Request $request): PageTranslation|JsonResponse
    {
        $translation = $this->translationRepository->find($id);
        if (!$translation instanceof PageTranslation) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_PREFIX.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $revision = $request->request->get('revision');
        if (!is_string($revision) || !ctype_digit($revision) || (int) $revision !== $translation->getDraftRevision()) {
            return new JsonResponse(['revision' => $translation->getDraftRevision()], Response::HTTP_CONFLICT);
        }

        return $translation;
    }
}
