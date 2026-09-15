<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\CaseFlow\Application\CaseFlowCriteriaFactory;
use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegment;
use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegmentCatalogService;
use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegmentProfileDimension;
use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegmentProfileService;
use App\Statistics\UI\Http\Controller\StatisticsFilterValueResolver;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class CaseFlowSegmentProfileController extends AbstractController
{
    public function __construct(
        private readonly CaseFlowCriteriaFactory $criteriaFactory,
        private readonly GeographicSegmentCatalogService $segmentCatalogService,
        private readonly GeographicSegmentProfileService $profileService,
    ) {
    }

    #[Route('/statistics/case-flow/segment-profile', name: 'app_stats_case_flow_segment_profile', methods: ['GET'])]
    public function frame(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $criteria = $this->criteriaFactory->create($user, $filter, $request);
        $catalog = $this->segmentCatalogService->build($criteria);
        $selectedSegment = GeographicSegment::tryFromQueryValue($request->query->getString(StatisticsQueryKeys::GEO_SEGMENT));
        $dimension = GeographicSegmentProfileDimension::fromQueryValue(
            $request->query->getString(StatisticsQueryKeys::GEO_PROFILE),
        );

        $profile = $this->profileService->build($criteria, $catalog, $selectedSegment, $dimension);

        return $this->render('@Statistics/case_flow/_segment_profile_frame.html.twig', [
            'profile' => $profile,
            'segmentProfileDimension' => $dimension,
        ]);
    }
}
