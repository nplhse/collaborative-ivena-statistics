<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\GenericAnalysis\Application\AnalysisPresetRegistry;
use App\Statistics\GenericAnalysis\Domain\Exception\UnknownAnalysisPresetException;
use App\Statistics\UI\Http\Controller\StatisticsFilterValueResolver;
use App\Statistics\UI\Http\Controller\StatisticsPublicScopeRedirector;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class GenericAnalysisController extends AbstractController
{
    public function __construct(
        private readonly AnalysisPresetRegistry $presetRegistry,
        private readonly StatisticsPublicScopeRedirector $publicScopeRedirector,
    ) {
    }

    #[Route(
        '/statistics/generic-analysis/{presetKey}',
        name: 'app_stats_generic_analysis',
        methods: ['GET'],
    )]
    public function __invoke(
        string $presetKey,
        Request $request,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): \Symfony\Component\HttpFoundation\RedirectResponse {
        $publicRedirect = $this->publicScopeRedirector->maybeRedirectPayload($request, $filter);
        if (null !== $publicRedirect) {
            if (null !== $publicRedirect['notice']) {
                $this->addFlash('error', $publicRedirect['notice']->value);
            }

            return $this->redirectToRoute('app_stats_analytics_view', array_merge(
                ['viewKey' => $presetKey],
                $publicRedirect['query'],
            ));
        }

        if ('custom' === $presetKey) {
            if (!$this->isGranted('ROLE_PARTICIPANT')) {
                throw $this->createAccessDeniedException();
            }

            return $this->redirectToRoute('app_stats_analytics_builder', $this->scopeQuery($request));
        }

        try {
            $this->presetRegistry->get($presetKey);
        } catch (UnknownAnalysisPresetException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }

        return $this->redirectToRoute('app_stats_analytics_view', array_merge(
            ['viewKey' => $presetKey],
            $this->scopeQuery($request),
        ));
    }

    /**
     * @return array<string, string>
     */
    private function scopeQuery(Request $request): array
    {
        $query = [];
        foreach ($request->query->all() as $key => $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $query[$key] = (string) $value;
        }

        return $query;
    }
}
