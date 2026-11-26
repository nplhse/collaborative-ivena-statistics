<?php

declare(strict_types=1);

namespace App\Research\UI\Http\Controller\DataRequest;

use App\Research\Application\DataRequest\DataRequestRefused;
use App\Research\Application\DataRequest\DataRequestReleaseService;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Entity\DataRequestExportRun;
use App\Research\Domain\Entity\DataRequestRelease;
use App\Research\Infrastructure\Repository\DataRequestRepository;
use App\Research\Infrastructure\Security\Voter\DataRequestVoter;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Release and export of an approved data extract request (ADR 045): administrators release it, the applicant starts
 * the export, administrators try a failed run again, the applicant and administrators download the archive.
 */
#[IsGranted('ROLE_PARTICIPANT')]
final class ReleaseDataRequestController extends AbstractController
{
    public const string CSRF_PREFIX = 'data_request_release_';

    public function __construct(
        private readonly DataRequestRepository $requests,
        private readonly DataRequestReleaseService $releases,
    ) {
    }

    #[Route('/requests/data/{publicId}/releases', name: 'app_data_requests_release', requirements: ['publicId' => Requirement::UUID], methods: ['POST'])]
    public function create(Request $request, string $publicId): RedirectResponse
    {
        [$user, $dataRequest] = $this->load($publicId);
        if (!$this->isGranted(DataRequestVoter::RELEASE, $dataRequest)) {
            throw $this->createAccessDeniedException();
        }

        $this->assertToken($request, $publicId);

        return $this->attempt($publicId, 'released', fn (): DataRequestRelease => $this->releases->release(
            $user,
            $dataRequest,
            $request->getPayload()->getBoolean('single_cases_confirmed'),
            array_values(array_filter($request->getPayload()->all('queries'), is_string(...))),
            $request->getPayload()->getString('conditions'),
        ));
    }

    #[Route('/requests/data/{publicId}/releases/{number}/{action}', name: 'app_data_requests_export', requirements: ['publicId' => Requirement::UUID, 'number' => Requirement::POSITIVE_INT, 'action' => 'start|retry|revoke'], methods: ['POST'])]
    public function export(Request $request, string $publicId, int $number, string $action): RedirectResponse
    {
        [$user, $dataRequest] = $this->load($publicId);
        $release = $this->findRelease($dataRequest, $number);
        $run = $release->getRun();
        $allowed = match ($action) {
            'start' => $this->releases->canStart($user, $release),
            'revoke' => $this->releases->canRevoke($user, $release),
            default => $this->isGranted('ROLE_ADMIN') && $run instanceof DataRequestExportRun,
        };
        if (!$allowed) {
            throw $this->createAccessDeniedException();
        }

        $this->assertToken($request, $publicId);
        $payload = $request->getPayload();

        return $this->attempt($publicId, 'export_'.$action, fn () => match ($action) {
            'start' => $this->releases->start($user, $release, $request->getLocale(), $payload->getBoolean('accept_conditions')),
            'revoke' => $this->releases->revoke($user, $release, $payload->getString('reason')),
            default => $this->releases->retry($user, $this->run($release)),
        });
    }

    #[Route('/requests/data/{publicId}/releases/{number}/download', name: 'app_data_requests_export_download', requirements: ['publicId' => Requirement::UUID, 'number' => Requirement::POSITIVE_INT], methods: ['GET'])]
    public function download(string $publicId, int $number): BinaryFileResponse
    {
        [$user, $dataRequest] = $this->load($publicId);
        $run = $this->run($this->findRelease($dataRequest, $number));
        try {
            $path = $this->releases->download($user, $run);
        } catch (DataRequestRefused) {
            throw $this->createAccessDeniedException();
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, DataRequestReleaseService::archiveName($run));
        $response->headers->set('Content-Type', 'application/gzip');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * @return array{User, DataRequest}
     */
    private function load(string $publicId): array
    {
        $user = $this->getUser();
        $dataRequest = $this->requests->findOneByPublicId($publicId);
        if (!$user instanceof User || !$dataRequest instanceof DataRequest || !$this->isGranted(DataRequestVoter::VIEW_INTERNAL, $dataRequest)) {
            throw $this->createNotFoundException();
        }

        return [$user, $dataRequest];
    }

    private function findRelease(DataRequest $dataRequest, int $number): DataRequestRelease
    {
        return $dataRequest->findRelease($number) ?? throw $this->createNotFoundException();
    }

    private function run(DataRequestRelease $release): DataRequestExportRun
    {
        return $release->getRun() ?? throw $this->createNotFoundException();
    }

    private function assertToken(Request $request, string $publicId): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_PREFIX.$publicId, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function attempt(string $publicId, string $flash, callable $step): RedirectResponse
    {
        try {
            $step();
            $this->addFlash('success', new TranslatableMessage('data_request.flash.'.$flash, domain: 'research'));
        } catch (DataRequestRefused $refused) {
            $this->addFlash('error', new TranslatableMessage($refused->reason, domain: 'research'));
        }

        return $this->redirect($this->generateUrl('app_data_requests_show', ['publicId' => $publicId]).'#release');
    }
}
