<?php

declare(strict_types=1);

namespace App\Content\UI\Http\Controller;

use App\Content\UI\Http\DTO\ActivityTimelineQueryParametersDTO;
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
final class ActivityTimelineFeedController extends AbstractController
{
    public function __construct(
        private readonly ProjectActivityQueryInterface $activityQuery,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/activity/feed', name: 'app_activity_timeline_feed', methods: ['GET'])]
    public function __invoke(#[MapQueryString] ActivityTimelineQueryParametersDTO $query): Response
    {
        $frameId = null !== $query->cursor
            ? ProjectActivityCursor::frameId($query->cursor, ProjectActivityCursor::FRAME_PREFIX_TIMELINE)
            : 'activity-timeline';

        try {
            $page = $this->activityQuery->getPage(
                $query->cursor,
                ProjectActivityPage::PAGE_SIZE,
                $query->toFilters(),
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Activity timeline feed failed.', [
                'exception' => $exception,
            ]);

            return $this->render('@Content/activity/_error.html.twig', [
                'frameId' => $frameId,
            ]);
        }

        return $this->render('@Content/activity/_feed_page.html.twig', [
            'page' => $page,
            'frameId' => $frameId,
            'query' => $query,
            'nextFrameId' => $page->nextFrameId(ProjectActivityCursor::FRAME_PREFIX_TIMELINE),
        ]);
    }
}
