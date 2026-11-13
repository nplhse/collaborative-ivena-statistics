<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Audit;

use App\Admin\Application\Audit\AuditTimelineCursor;
use App\Admin\Application\Audit\AuditTimelineFilter;
use App\Admin\Application\Audit\AuditTimelineService;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Audit timeline (#955, ADR 025): entries newest first, entries of one request stacked.
 */
#[IsGranted('ROLE_ADMIN')]
#[AdminRoute(path: '/audit', name: 'audit')]
final class AuditTimelineController extends AbstractController
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly AuditTimelineService $timeline,
    ) {
    }

    #[AdminRoute(path: '/', name: 'timeline', options: ['methods' => ['GET']])]
    public function index(Request $request): Response
    {
        $filter = AuditTimelineFilter::fromQuery($request->query->all());
        $cursor = AuditTimelineCursor::tryDecode($request->query->getString('after'));

        return $this->render('@Admin/audit/timeline.html.twig', [
            'page' => $this->timeline->page($filter, $cursor),
            'filter' => $filter,
            'is_first_page' => !$cursor instanceof AuditTimelineCursor,
            'options' => $this->timeline->filterOptions(),
            'actions' => AuditTimelineFilter::ACTIONS,
            'origins' => AuditTimelineFilter::ORIGINS,
            'outcomes' => AuditTimelineFilter::OUTCOMES,
        ]);
    }

    #[AdminRoute(path: '/requests/{requestId}', name: 'request', options: ['methods' => ['GET'], 'requirements' => ['requestId' => '[A-Za-z0-9._-]{1,128}']])]
    public function request(string $requestId, Request $request): Response
    {
        $filter = AuditTimelineFilter::fromQuery($request->query->all());
        $result = $this->timeline->request($requestId, $filter);
        if ([] === $result['entries']) {
            throw $this->createNotFoundException();
        }

        return $this->render('@Admin/audit/request.html.twig', [
            'request_id' => $requestId,
            'entries' => $result['entries'],
            'stats' => $result['stats'],
            'matching_ids' => $result['matchingIds'],
            'filter' => $filter,
            'limit' => AuditTimelineService::REQUEST_LIMIT,
        ]);
    }

    /**
     * Permalink of one entry with its changes and metadata.
     */
    #[AdminRoute(path: '/entries/{id}', name: 'entry', options: ['methods' => ['GET'], 'requirements' => ['id' => '\\d+']])]
    public function entry(int $id): Response
    {
        $details = $this->timeline->details($id) ?? throw $this->createNotFoundException();

        return $this->render('@Admin/audit/entry.html.twig', ['details' => $details]);
    }

    /**
     * The same details as a fragment, loaded when an entry is expanded in the timeline.
     */
    #[AdminRoute(path: '/entries/{id}/details', name: 'entry_details', options: ['methods' => ['GET'], 'requirements' => ['id' => '\\d+']])]
    public function entryDetails(int $id): Response
    {
        $details = $this->timeline->details($id) ?? throw $this->createNotFoundException();

        return $this->render('@Admin/audit/_entry_details.html.twig', ['details' => $details]);
    }
}
