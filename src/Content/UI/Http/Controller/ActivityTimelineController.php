<?php

declare(strict_types=1);

namespace App\Content\UI\Http\Controller;

use App\Content\UI\Http\DTO\ActivityTimelineQueryParametersDTO;
use App\User\Application\Contract\PublishedPostPreviewMapInterface;
use App\User\Application\Explore\PostPublishedActivitySlugs;
use App\User\Application\Explore\ProjectActivityCursor;
use App\User\Application\Explore\ProjectActivityPage;
use App\User\Application\Explore\ProjectActivityQueryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** @psalm-suppress UnusedClass */
#[IsGranted('ROLE_USER')]
final class ActivityTimelineController extends AbstractController
{
    public function __construct(
        private readonly ProjectActivityQueryInterface $activityQuery,
        private readonly PublishedPostPreviewMapInterface $postPreviews,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/activity', name: 'app_activity_timeline', methods: ['GET'])]
    public function __invoke(#[MapQueryString] ActivityTimelineQueryParametersDTO $query): Response
    {
        try {
            $page = $this->activityQuery->getPage(null, ProjectActivityPage::PAGE_SIZE, $query->toFilters());
        } catch (\Throwable $exception) {
            $this->logger->error('Activity timeline failed.', [
                'exception' => $exception,
            ]);

            return $this->render('@Content/activity/index.html.twig', [
                'query' => $query,
                'page' => new ProjectActivityPage([], null),
                'previews' => [],
                'feedError' => true,
                'periodPresets' => $this->periodPresets(),
                'nextFrameId' => null,
            ]);
        }

        return $this->render('@Content/activity/index.html.twig', [
            'query' => $query,
            'page' => $page,
            'previews' => $this->postPreviews->forSlugs(PostPublishedActivitySlugs::from($page->items)),
            'feedError' => false,
            'periodPresets' => $this->periodPresets(),
            'nextFrameId' => $page->nextFrameId(ProjectActivityCursor::FRAME_PREFIX_TIMELINE),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function periodPresets(): array
    {
        $today = new \DateTimeImmutable('today');

        return [
            7 => $today->modify('-7 days')->format('Y-m-d'),
            30 => $today->modify('-30 days')->format('Y-m-d'),
            90 => $today->modify('-90 days')->format('Y-m-d'),
        ];
    }
}
