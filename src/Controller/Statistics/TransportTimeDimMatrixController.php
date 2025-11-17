<?php

declare(strict_types=1);

namespace App\Controller\Statistics;

use App\DataTransferObjects\TransportTimeRequest;
use App\Service\Statistics\TransportTimeDimMatrixReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class TransportTimeDimMatrixController extends AbstractController
{
    #[Route('/stats/transport-time/matrix', name: 'app_stats_transport_time_dim_matrix')]
    public function __invoke(
        Request $request,
        TransportTimeDimMatrixReader $reader,
    ): Response {
        // Normalize request into a Scope, re-using your existing DTO
        $dto = TransportTimeRequest::fromRequest($request);
        $scope = $dto->toScope();

        // Which dimension type? (occasion, assignment, dispatch_area, state, ...)
        $dimType = $request->query->get('dim', 'occasion');

        // Read matrix data from the reader
        $rows = $reader->readMatrix($scope, $dimType);

        // Fixed bucket order – ideally aus einer Konstanten wie AbstractTransportTimeBase::BUCKET_KEYS
        $bucketKeys = [
            '<10',
            '10-20',
            '20-30',
            '30-40',
            '40-50',
            '50-60',
            '>60',
        ];

        return $this->render('stats/transport_time_dim_matrix.html.twig', [
            'scope' => $scope,
            'dimType' => $dimType,
            'bucketKeys' => $bucketKeys,
            'rows' => $rows,
            // einfach schon mal für spätere UI:
            'availableDimTypes' => [
                'occasion' => 'Occasion',
                'assignment' => 'Assignment',
                'dispatch_area' => 'Dispatch Area',
                'state' => 'State',
                'speciality' => 'Speciality',
                'indication' => 'Indication',
            ],
            'view' => $dto->view,
        ]);
    }
}
