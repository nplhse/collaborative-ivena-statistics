<?php

declare(strict_types=1);

namespace App\Import\UI\Http\Controller;

use App\Import\Application\Service\ImportUploadGuard;
use App\Import\Application\Service\PendingImportUploadStore;
use App\Import\Domain\Validation\Constraints\ImportSourceFile;
use App\Import\Infrastructure\Security\Voter\ImportCreateVoter;
use App\Shared\Application\RateLimit\ClientRateLimit;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Background upload of the import form (#977): stores the selected file as a pending upload of the current
 * user and answers with its token. Creates no import; the form submission consumes the token and runs the
 * regular checks again. Same file rules as the form (type, Excel rejection, size), CSRF token `import_upload`.
 */
#[IsGranted('ROLE_PARTICIPANT')]
final class PendingImportUploadController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'import_upload';

    public function __construct(
        private readonly PendingImportUploadStore $store,
        private readonly ImportUploadGuard $uploadGuard,
        private readonly ValidatorInterface $validator,
        private readonly TranslatorInterface $translator,
        #[Autowire(param: 'app.imports_max_size')]
        private readonly string $maxSize,
    ) {
    }

    #[Route('/import/upload', name: 'app_import_upload', methods: ['POST'])]
    public function upload(
        Request $request,
        #[Autowire(service: 'limiter.import_upload')]
        RateLimiterFactory $importUploadLimiter,
    ): JsonResponse {
        $userId = $this->authorizedUserId($request);

        if (!ClientRateLimit::acceptUserAndIp($importUploadLimiter, 'import_upload', (string) $userId, $request)) {
            return $this->error('flash.import.rate_limited', 'import', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->error('validation.import.upload_failed', 'validators', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $violations = $this->validator->validate($file, [new ImportSourceFile(), new Assert\File(maxSize: $this->maxSize)]);
        if (\count($violations) > 0) {
            return $this->json(['error' => (string) $violations->get(0)->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The guard also checks the detected MIME type, which the constraint skips for some clients.
        $rejection = $this->uploadGuard->resolveRejectionMessageKey($file);
        if (null !== $rejection) {
            return $this->error($rejection, 'validators', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $upload = $this->store->store($file, $userId);
        } catch (FileException) {
            return $this->error('validation.import.upload_failed', 'validators', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // The file this one replaces is gone at once; an unknown token is ignored.
        $this->store->delete($request->request->getString('replaces'), $userId);

        return $this->json([
            'token' => $upload->token,
            'name' => $upload->originalName,
            'size' => $upload->size,
        ], Response::HTTP_CREATED);
    }

    #[Route('/import/upload/{token}/discard', name: 'app_import_upload_discard', requirements: ['token' => '[0-9a-f]{32}'], methods: ['POST'])]
    public function discard(Request $request, string $token): Response
    {
        $this->store->delete($token, $this->authorizedUserId($request));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function authorizedUserId(Request $request): int
    {
        $user = $this->getUser();
        $userId = $user instanceof User ? $user->getId() : null;
        if (null === $userId || !$this->isGranted(ImportCreateVoter::CREATE)) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        return $userId;
    }

    private function error(string $key, string $domain, int $status): JsonResponse
    {
        return $this->json(['error' => $this->translator->trans($key, [], $domain)], $status);
    }
}
