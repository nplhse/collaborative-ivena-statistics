<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller\Distribution;

use App\Statistics\Application\Panel\Distribution\DistributionPageConfig;
use App\Statistics\Application\Panel\Distribution\DistributionPageConfigFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/statistics/distribution/age', name: 'app_stats_distribution_age', methods: ['GET'])]
final class AgeCohortDistributionController extends AbstractController
{
    public function __construct(
        private readonly DistributionPageConfigFactory $distributionPageConfigFactory,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $config = $this->distributionPageConfigFactory->forPageId(DistributionPageConfigFactory::PAGE_AGE_COHORT);
        $request->attributes->set(DistributionPageConfig::REQUEST_ATTRIBUTE, $config);

        return $this->render('@Statistics/distribution/index.html.twig', [
            'distributionPageId' => DistributionPageConfigFactory::PAGE_AGE_COHORT,
        ]);
    }
}
