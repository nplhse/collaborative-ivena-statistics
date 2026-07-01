<?php

declare(strict_types=1);

namespace App\Feedback\UI\Http\Controller;

use App\Feedback\Application\FeedbackSpamChecker;
use App\Feedback\Application\FeedbackSpamCheckResult;
use App\Feedback\Application\FeedbackSpamRejectLogger;
use App\Feedback\Application\RecordFeedbackHandler;
use App\Feedback\Domain\Enum\FeedbackCategory;
use App\Feedback\UI\Form\FeedbackSubmitFormType;
use App\Feedback\UI\Http\FeedbackRedirectTargetResolver;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

final class FeedbackSubmitController extends AbstractController
{
    public function __construct(
        private readonly RecordFeedbackHandler $recordFeedbackHandler,
        private readonly FeedbackRedirectTargetResolver $redirectTargetResolver,
        private readonly FeedbackSpamChecker $feedbackSpamChecker,
        private readonly FeedbackSpamRejectLogger $feedbackSpamRejectLogger,
    ) {
    }

    #[Route('/feedback', name: 'app_feedback_submit', methods: ['POST'])]
    public function __invoke(
        Request $request,
        #[Autowire(service: 'limiter.feedback_submit')]
        RateLimiterFactory $feedbackSubmitLimiter,
        #[Autowire(service: 'limiter.feedback_submit_anonymous_ip')]
        RateLimiterFactory $anonymousIpLimiter,
        #[Autowire(service: 'limiter.feedback_submit_anonymous_email')]
        RateLimiterFactory $anonymousEmailLimiter,
        #[Autowire('%app.version%')]
        string $appVersion,
    ): RedirectResponse {
        $user = $this->getUser();

        $guestRequired = !$user instanceof User;
        $form = $this->createForm(FeedbackSubmitFormType::class, null, [
            'guest_email_required' => $guestRequired,
        ]);
        $form->handleRequest($request);

        $redirectRaw = $form->get('_redirect_target')->getData();
        $target = $this->redirectTargetResolver->resolve(\is_scalar($redirectRaw) ? (string) $redirectRaw : '/');

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('danger', new TranslatableMessage('feedback.flash.validation_error', domain: 'feedback'));

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
            if ($guestRequired) {
                $this->logSpamRejected($request, null, null, null, 'feedback_submit');

                return $this->successRedirect($target);
            }

            $this->addFlash('warning', new TranslatableMessage('feedback.flash.rate_limited', domain: 'feedback'));

            return $this->redirect($target);
        }

        /** @var array<string, mixed> $data */
        $data = $form->getData();
        $category = $data['category'] ?? null;
        if (!$category instanceof FeedbackCategory) {
            $this->addFlash('danger', new TranslatableMessage('feedback.flash.validation_error', domain: 'feedback'));

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

        if ($guestRequired) {
            $anonymousIpKey = 'anon_ip_'.sha1($request->getClientIp() ?? 'unknown');
            if (!$anonymousIpLimiter->create($anonymousIpKey)->consume(1)->isAccepted()) {
                $this->logSpamRejected($request, null, $guestEmail, null, 'anonymous_ip');

                return $this->successRedirect($target);
            }

            if (null !== $guestEmail) {
                $anonymousEmailKey = 'anon_email_'.sha1(mb_strtolower($guestEmail));
                if (!$anonymousEmailLimiter->create($anonymousEmailKey)->consume(1)->isAccepted()) {
                    $this->logSpamRejected($request, null, $guestEmail, null, 'anonymous_email');

                    return $this->successRedirect($target);
                }
            }
        }

        $honeypotRaw = $form->get('website')->getData();
        $honeypotValue = \is_string($honeypotRaw) ? $honeypotRaw : null;
        $renderedAtRaw = $form->get('renderedAt')->getData();
        $renderedAtValue = \is_scalar($renderedAtRaw) ? (string) $renderedAtRaw : null;
        $renderedAtTimestamp = (null !== $renderedAtValue && '' !== trim($renderedAtValue) && ctype_digit($renderedAtValue))
            ? (int) $renderedAtValue
            : null;
        $spamDecision = $this->feedbackSpamChecker->check(
            $message,
            $honeypotValue,
            $renderedAtTimestamp,
            time(),
            $user instanceof User,
        );
        if ($spamDecision->isSpam()) {
            $this->logSpamRejected($request, $user instanceof User ? $user : null, $guestEmail, $spamDecision, '');

            return $this->successRedirect($target);
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
                $routeParams = \is_array($decoded) ? $this->filterRouteParams($decoded) : [];
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

        return $this->successRedirect($target);
    }

    /**
     * @param array<mixed> $params
     *
     * @return array<string, mixed>
     */
    private function filterRouteParams(array $params): array
    {
        $filtered = [];
        foreach ($params as $key => $value) {
            if (!\is_string($key) || str_starts_with($key, '_')) {
                continue;
            }

            $filtered[$key] = $value;
        }

        return $filtered;
    }

    private function successRedirect(string $target): RedirectResponse
    {
        $this->addFlash('success', new TranslatableMessage('feedback.flash.success', domain: 'feedback'));

        return $this->redirect($target);
    }

    private function logSpamRejected(
        Request $request,
        ?User $user,
        ?string $guestEmail,
        ?FeedbackSpamCheckResult $spamDecision,
        string $rateLimiterHit,
    ): void {
        $this->feedbackSpamRejectLogger->logRejected(
            $user,
            $guestEmail,
            $request->getClientIp(),
            $spamDecision,
            $rateLimiterHit,
        );
    }
}
