<?php

declare(strict_types=1);

namespace App\Research\UI\Http\Controller\DataRequest;

use App\Research\Application\DataRequest\DataRequestQueryService;
use App\Research\Application\DataRequest\DataRequestRefused;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Entity\DataRequestQuery;
use App\Research\Domain\Enum\QueryKind;
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
 * Administrators add, change and dry-run the export queries of an approved data extract request (ADR 045).
 */
#[IsGranted('ROLE_ADMIN')]
final class ManageDataRequestQueryController extends AbstractController
{
    public const string CSRF_PREFIX = 'data_request_query_';

    public function __construct(
        private readonly DataRequestRepository $requests,
        private readonly DataRequestQueryService $queries,
    ) {
    }

    #[Route('/requests/data/{publicId}/queries/{action}', name: 'app_data_requests_query', requirements: ['publicId' => Requirement::UUID, 'action' => 'add|revise|dry-run'], methods: ['POST'])]
    public function __invoke(Request $request, string $publicId, string $action): RedirectResponse
    {
        $user = $this->getUser();
        $dataRequest = $this->requests->findOneByPublicId($publicId);
        if (!$user instanceof User || !$dataRequest instanceof DataRequest) {
            throw $this->createNotFoundException();
        }

        if (!$this->isGranted(DataRequestVoter::MANAGE_QUERIES, $dataRequest)) {
            throw $this->createAccessDeniedException();
        }

        $payload = $request->getPayload();
        if (!$this->isCsrfTokenValid(self::CSRF_PREFIX.$publicId, $payload->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $name = $payload->getString('name');
        try {
            match ($action) {
                'add' => $this->queries->add($user, $dataRequest, $name, $payload->getString('description'), QueryKind::tryFrom($payload->getString('kind')) ?? QueryKind::Aggregated, $payload->getString('sql')),
                'revise' => $this->queries->revise($user, $this->query($dataRequest, $name), $payload->getString('description'), $payload->getString('sql')),
                'dry-run' => $this->queries->dryRun($user, $this->query($dataRequest, $name)),
                default => throw $this->createNotFoundException(),
            };
            $this->addFlash('success', new TranslatableMessage('data_request.flash.query.'.$action, domain: 'research'));
        } catch (DataRequestRefused $refused) {
            $this->addFlash('error', new TranslatableMessage($refused->reason, domain: 'research'));
        }

        return $this->redirect($this->generateUrl('app_data_requests_show', ['publicId' => $publicId]).'#queries');
    }

    /**
     * @throws DataRequestRefused
     */
    private function query(DataRequest $dataRequest, string $name): DataRequestQuery
    {
        return $dataRequest->findQuery($name) ?? throw new DataRequestRefused(DataRequestRefused::NOT_ALLOWED);
    }
}
