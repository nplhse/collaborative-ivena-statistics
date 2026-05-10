<?php

declare(strict_types=1);

namespace App\Feedback\UI\Http\Controller;

use App\Feedback\UI\Form\FeedbackSubmitFormType;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FeedbackWidgetController extends AbstractController
{
    #[Route('/_ui/feedback/widget', name: 'app_feedback_widget', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        $returnPathRaw = $request->query->getString('return_path', '/');

        $form = $this->createForm(FeedbackSubmitFormType::class, null, [
            'guest_email_required' => !$user instanceof User,
            'action' => $this->generateUrl('app_feedback_submit'),
            'method' => 'POST',
        ]);

        $form->get('_redirect_target')->setData($this->resolveSafeLocalPath($returnPathRaw));

        $route = $request->query->getString('return_route', '');
        $form->get('_source_route')->setData($route);

        $paramsJson = $request->query->get('return_route_params');
        $form->get('_source_route_params')->setData(\is_string($paramsJson) && '' !== $paramsJson ? $paramsJson : '{}');

        $extra = $request->query->getString('feedback_extra_context', '');
        if ('' !== $extra) {
            $form->get('extraContext')->setData($extra);
        }

        return $this->render('@Feedback/_includes/feedback_widget.html.twig', [
            'form' => $form->createView(),
        ]);
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
