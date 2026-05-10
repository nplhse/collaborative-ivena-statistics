<?php

declare(strict_types=1);

namespace App\Feedback\UI\Http\Controller;

use App\Feedback\Application\RecordFeedbackHandler;
use App\Feedback\Domain\Enum\FeedbackCategory;
use App\Feedback\UI\Form\FeedbackSubmitFormType;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class FeedbackSubmitController extends AbstractController
{
    public function __construct(private readonly RecordFeedbackHandler $recordFeedbackHandler)
    {
    }

    #[Route('/feedback', name: 'app_feedback_submit', methods: ['POST'])]
    public function __invoke(
        Request $request,
        #[Autowire(service: 'limiter.feedback_submit')]
        RateLimiterFactory $feedbackSubmitLimiter,
        #[Autowire('%app.feedback.app_version%')]
        string $appVersion,
    ): RedirectResponse {
        $user = $this->getUser();

        $guestRequired = !$user instanceof User;
        $form = $this->createForm(FeedbackSubmitFormType::class, null, [
            'guest_email_required' => $guestRequired,
        ]);
        $form->handleRequest($request);

        $redirectRaw = $form->get('_redirect_target')->getData();
        $target = $this->resolveSafeLocalPath(\is_scalar($redirectRaw) ? (string) $redirectRaw : '/');

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('danger', 'feedback.flash.validation_error');

            return $this->redirect($target);
        }

        $userIdPart = '0';
        if ($user instanceof User) {
            $id = $user->getId();
            $userIdPart = null !== $id ? (string) $id : '0';
        }

        $limiterKey = sprintf(
            'feedback_%s_%s',
            $guestRequired ? ('ip_'.sha1($request->getClientIp() ?? 'unknown')) : 'user_'.$userIdPart,
            $request->getClientIp() ?? 'unknown'
        );
        $limit = $feedbackSubmitLimiter->create($limiterKey)->consume(1);

        if (!$limit->isAccepted()) {
            $this->addFlash('warning', 'feedback.flash.rate_limited');

            return $this->redirect($target);
        }

        /** @var array<string, mixed> $data */
        $data = $form->getData();
        $category = $data['category'] ?? null;
        if (!$category instanceof FeedbackCategory) {
            $this->addFlash('danger', 'feedback.flash.validation_error');

            return $this->redirect($target);
        }

        $message = isset($data['message']) && \is_string($data['message']) ? $data['message'] : '';

        $guestEmail = null;
        if ($guestRequired) {
            $ge = $data['guestEmail'] ?? null;
            $guestEmail = \is_string($ge) && '' !== trim($ge) ? trim($ge) : null;
        } elseif ($user instanceof User) {
            $guestEmail = null;
        }

        $extraRaw = $form->get('extraContext')->getData();
        $extra = [];
        if (\is_string($extraRaw) && '' !== trim($extraRaw)) {
            try {
                $decoded = json_decode($extraRaw, true, 512, JSON_THROW_ON_ERROR);
                $extra = \is_array($decoded) ? $decoded : [];
            } catch (\JsonException) {
                $extra = [];
            }
        }

        $routeName = $form->get('_source_route')->getData();
        if (!\is_string($routeName) || '' === trim($routeName)) {
            $routeName = null;
        } elseif (!preg_match('/^[a-zA-Z0-9_.]+$/D', $routeName)) {
            $routeName = null;
        }

        $routeParams = [];
        $paramsRaw = $form->get('_source_route_params')->getData();
        if (\is_string($paramsRaw) && '' !== trim($paramsRaw)) {
            try {
                $decoded = json_decode($paramsRaw, true, 512, JSON_THROW_ON_ERROR);
                $routeParams = \is_array($decoded) ? $decoded : [];
            } catch (\JsonException) {
                $routeParams = [];
            }
        }

        $context = [
            'route_params' => $routeParams,
            'extra' => $extra,
        ];

        $pageUrl = $request->getSchemeAndHttpHost().$target;

        $appVersionTrim = trim($appVersion);

        $this->recordFeedbackHandler->execute(
            $category,
            $message,
            $guestEmail,
            $user instanceof User ? $user : null,
            $pageUrl,
            $routeName,
            $context,
            $request->headers->get('User-Agent'),
            '' !== $appVersionTrim ? $appVersionTrim : null,
        );

        $this->addFlash('success', 'feedback.flash.success');

        return $this->redirect($target);
    }

    private function resolveSafeLocalPath(string $target): string
    {
        $target = trim($target);
        if ('' === $target || !str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return '/';
        }

        return $target;
    }
}
