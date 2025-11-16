<?php

declare(strict_types=1);

namespace App\Controller\Statistics;

use App\DataTransferObjects\TransportTimeRequest;
use App\Service\Statistics\TransportTimeBucketPresets;
use App\Service\Statistics\TransportTimeReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class TransportTimeController extends AbstractController
{
    #[Route('/stats/transport-time', name: 'app_stats_transport_time')]
    public function __invoke(
        Request $request,
        TransportTimeReader $reader,
    ): Response {
        $dto = TransportTimeRequest::fromRequest($request);
        $scope = $dto->toScope();

        $viewModel = $reader->read($scope);

        $hasData = null !== $viewModel->getComputedAt();

        return $this->render('stats/transport_time.html.twig', [
            'requestDto' => $dto,
            'scope' => $scope,
            'viewModel' => $viewModel,
            'view' => $dto->view,
            'hasData' => $hasData,
            'currentPreset' => $dto->preset,
            'currentBucket' => $dto->bucket,
            'buckets' => TransportTimeBucketPresets::all(),
            'withProgress' => $dto->withProgress,
            'withPhysician' => $dto->withPhysician,
            'topAnchorId' => 'transport-time-top',
        ]);
    }
}
