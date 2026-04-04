<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller\Distribution;

use App\Statistics\Application\Panel\Distribution\DistributionPageConfig;
use App\Statistics\Application\Panel\Distribution\DistributionPageConfigFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/statistics/distribution/gender', name: 'app_stats_distribution_gender', methods: ['GET'])]
final class GenderDistributionController extends AbstractController
{
    public function __construct(
        private readonly DistributionPageConfigFactory $distributionPageConfigFactory,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $config = $this->distributionPageConfigFactory->forPageId(DistributionPageConfigFactory::PAGE_GENDER);
        $request->attributes->set(DistributionPageConfig::REQUEST_ATTRIBUTE, $config);

        return $this->render('@Statistics/distribution/index.html.twig', [
            'distributionPageId' => DistributionPageConfigFactory::PAGE_GENDER,
        ]);
    }
}
