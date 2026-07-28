<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Export;

use App\Allocation\Application\Export\ExportHospitalFormOptionsProvider;
use App\Allocation\Application\Export\OwnHospitalAllocationsExporter;
use App\Allocation\Application\Export\OwnHospitalAllocationsExportFilterMapper;
use App\Allocation\Infrastructure\Security\Voter\ExportVoter;
use App\Allocation\UI\Form\OwnHospitalAllocationsExportType;
use App\Shared\Application\Export\ExportBlockedException;
use App\Shared\Application\Export\ExportOrchestrator;
use App\Shared\Application\RateLimit\ClientRateLimit;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PARTICIPANT')]
#[IsGranted(ExportVoter::EXPORT)]
final class DownloadAllocationsExportController extends AbstractController
{
    private const string CSRF_TOKEN_ID = 'export_allocations_csv';

    public function __construct(
        private readonly ExportOrchestrator $exportOrchestrator,
        private readonly OwnHospitalAllocationsExportFilterMapper $filterMapper,
        private readonly ExportHospitalFormOptionsProvider $exportHospitalFormOptionsProvider,
    ) {
    }

    #[Route('/hospitals/export/allocations/download', name: 'app_hospitals_export_allocations_download', methods: ['POST'])]
    public function __invoke(
        Request $request,
        #[CurrentUser] User $user,
        #[Autowire(service: 'limiter.allocations_export')]
        RateLimiterFactory $allocationsExportLimiter,
    ): StreamedResponse {
        $userId = $user->getId();
        if (null === $userId) {
            throw new \LogicException('Authenticated user has no ID.');
        }

        if (!ClientRateLimit::acceptUserAndIp($allocationsExportLimiter, 'allocations_export', (string) $userId, $request)) {
            throw new TooManyRequestsHttpException(message: 'Too many export requests. Please try again later.');
        }

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }

        $form = $this->createForm(
            OwnHospitalAllocationsExportType::class,
            null,
            $this->exportHospitalFormOptionsProvider->formOptionsFor($user, $request->getLocale()),
        );
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new BadRequestHttpException('Invalid export filter.');
        }

        $filter = $this->filterMapper->fromFormData($form->getData());

        try {
            return $this->exportOrchestrator->download(
                $user,
                OwnHospitalAllocationsExporter::KEY,
                $filter,
            );
        } catch (ExportBlockedException) {
            throw new BadRequestHttpException('Export exceeds the maximum row limit.');
        }
    }
}
