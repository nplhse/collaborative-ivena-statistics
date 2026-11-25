<?php

declare(strict_types=1);

namespace App\Research\UI\Http\Controller\DataRequest;

use App\Research\Application\DataRequest\DataRequestCommentService;
use App\Research\Application\DataRequest\DataRequestRefused;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Infrastructure\Repository\DataRequestRepository;
use App\Research\Infrastructure\Security\Voter\DataRequestVoter;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Posts a comment or a reply on a data extract request (ADR 045). The service enforces who may comment and the
 * visibility rules; a reply to a private comment stays private.
 */
#[IsGranted('ROLE_USER')]
final class CommentDataRequestController extends AbstractController
{
    public const string CSRF_PREFIX = 'data_request_comment_';

    public function __construct(
        private readonly DataRequestRepository $requests,
        private readonly DataRequestCommentService $comments,
    ) {
    }

    #[Route('/requests/data/{publicId}/comments', name: 'app_data_requests_comment', requirements: ['publicId' => Requirement::UUID], methods: ['POST'])]
    public function __invoke(Request $request, string $publicId): RedirectResponse
    {
        $user = $this->getUser();
        $dataRequest = $this->requests->findOneByPublicId($publicId);
        if (!$user instanceof User || !$dataRequest instanceof DataRequest || !$this->isGranted(DataRequestVoter::VIEW, $dataRequest)) {
            throw $this->createNotFoundException();
        }

        if (!$this->isGranted(DataRequestVoter::COMMENT, $dataRequest)) {
            throw $this->createAccessDeniedException();
        }

        $payload = $request->getPayload();
        if (!$this->isCsrfTokenValid(self::CSRF_PREFIX.$publicId, $payload->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $parentId = $payload->getInt('parent_id');
        try {
            $this->comments->post($user, $dataRequest, $payload->getString('body'), 'private' === $payload->getString('visibility'), $parentId > 0 ? $parentId : null);
            $this->addFlash('success', new TranslatableMessage('data_request.flash.commented', domain: 'research'));
        } catch (DataRequestRefused $refused) {
            $this->addFlash('error', new TranslatableMessage($refused->reason, domain: 'research'));
        }

        return $this->redirect($this->generateUrl('app_data_requests_show', ['publicId' => $publicId]).'#comments');
    }
}
