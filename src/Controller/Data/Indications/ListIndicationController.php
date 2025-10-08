<?php

namespace App\Controller\Data\Indications;

use App\DataTransferObjects\IndicationQueryParametersDTO;
use App\Repository\IndicationNormalizedRepository;
use App\Repository\IndicationRawRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/data/indication', name: 'app_data_indication_list')]
final class ListIndicationController extends AbstractController
{
    public function __construct(
        private readonly IndicationNormalizedRepository $indicationRepository,
        private readonly IndicationRawRepository $indicationRawRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        #[MapQueryString] IndicationQueryParametersDTO $query,
    ): Response {
        if ('normalized' === $query->type) {
            $paginator = $this->indicationRepository->getListPaginator($query);
        } else {
            $paginator = $this->indicationRawRepository->getListPaginator($query);
        }

        $tabs = [
            0 => [
                'name' => $this->translator->trans('indication.tab.normalized'),
                'path' => $this->generateUrl('app_data_indication_list', ['type' => 'normalized']),
                'active' => 'normalized' === $query->type,
            ],
            1 => [
                'name' => $this->translator->trans('indication.tab.raw'),
                'path' => $this->generateUrl('app_data_indication_list', ['type' => 'raw']),
                'active' => 'raw' === $query->type,
            ],
        ];

        return $this->render('data/indications/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_data_indication_list',
            'tabs' => $tabs,
            'active_type' => $query->type,
            'type' => $query->type,
            'search' => $query->search,
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
        ]);
    }
}
