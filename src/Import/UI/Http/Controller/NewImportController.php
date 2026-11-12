<?php

declare(strict_types=1);

namespace App\Import\UI\Http\Controller;

use App\Allocation\Application\Service\HospitalPermissionAccess;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Import\Application\Contracts\ImportTypeExtensionInterface;
use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Application\Message\ImportClosuresMessage;
use App\Import\Application\Service\DevelopmentHospitalOverride;
use App\Import\Application\Service\FileChecksumCalculator;
use App\Import\Application\Service\FileUploader;
use App\Import\Application\Service\ImportRunLifecycle;
use App\Import\Application\Service\ImportStartDispatcher;
use App\Import\Application\Service\ImportTypeExtensionRegistry;
use App\Import\Application\Service\ImportUploadGuard;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\ImportRun;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\UI\Form\ImportCreateType;
use App\Shared\Application\RateLimit\ClientRateLimit;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_PARTICIPANT')]
#[Route('/import/new', name: 'app_import_new', methods: ['GET', 'POST'])]
final class NewImportController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly FileChecksumCalculator $checksumCalculator,
        private readonly FileUploader $fileUploader,
        private readonly ImportUploadGuard $importUploadGuard,
        private readonly TranslatorInterface $translator,
        private readonly AuditContext $auditContext,
        private readonly HospitalPermissionAccess $hospitalPermissionAccess,
        private readonly ImportRunLifecycle $runLifecycle,
        private readonly DevelopmentHospitalOverride $hospitalOverride,
        private readonly ImportTypeExtensionRegistry $typeExtensions,
        private readonly ImportStartDispatcher $startDispatcher,
    ) {
    }

    public function __invoke(
        Request $request,
        #[Autowire(service: 'limiter.import_create')]
        RateLimiterFactory $importCreateLimiter,
    ): Response {
        $currentUser = $this->getUser();
        $extensions = $currentUser instanceof User ? $this->typeExtensions->availableTo($currentUser) : [];
        $form = $this->createForm(ImportCreateType::class, null, [
            'include_closure_type' => $this->isGranted(UserRole::CLOSURE_BETA),
            'type_extensions' => $extensions,
        ]);
        $form->handleRequest($request);

        // Remember-Me sessions are enough to start an import; do not re-add
        // IS_AUTHENTICATED_FULLY here (see #568). ROLE_PARTICIPANT and
        // HospitalPermission::Import remain the authorization boundary.

        if ($form->isSubmitted()) {
            $this->rejectUnsupportedImportFile($form);
        }

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderUploadForm($form, $extensions);
        }

        /** @var Hospital $hospital */
        $hospital = $form->get('hospital')->getData();
        $user = $this->getUser();
        $hospitalId = $hospital->getId();
        $importType = $this->resolveImportType($form);
        $extension = $this->typeExtensions->for($importType);
        if (!$user instanceof User || null === $hospitalId) {
            throw $this->createAccessDeniedException();
        }

        if ($extension instanceof ImportTypeExtensionInterface) {
            // Types of other contexts decide access themselves (`import.type_extension`).
            if (!$extension->isAvailableTo($user) || !\in_array($hospitalId, $extension->uploadHospitalIds($user), true)) {
                throw $this->createAccessDeniedException();
            }

            $errors = $extension->validateUpload($form, $hospital, $user);
            foreach ($errors as $field => $message) {
                $form->get($form->has($field) ? $field : 'file')->addError(new FormError($message));
            }

            if ([] !== $errors) {
                return $this->renderUploadForm($form, $extensions);
            }
        } elseif (!$this->hospitalPermissionAccess->hasPermission($user, $hospitalId, HospitalPermission::Import)) {
            throw $this->createAccessDeniedException();
        }

        $userId = $user->getId();
        if (null === $userId) {
            throw new \LogicException('Authenticated user has no ID.');
        }

        if (!ClientRateLimit::acceptUserAndIp($importCreateLimiter, 'import_create', (string) $userId, $request)) {
            $this->addFlash('warning', new TranslatableMessage('flash.import.rate_limited', domain: 'import'));

            return $this->redirectToRoute('app_import_new');
        }

        if (ImportType::CLOSURE === $importType && !$this->isGranted(UserRole::CLOSURE_BETA)) {
            throw $this->createAccessDeniedException();
        }

        /** @var Hospital $managedHospital */
        $managedHospital = $this->em->getReference(Hospital::class, $hospitalId);
        /** @var User $createdBy */
        $createdBy = $this->em->getReference(User::class, $userId);

        $name = (string) $form->get('name')->getData();

        /** @var UploadedFile $file */
        $file = $form->get('file')->getData();

        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', new TranslatableMessage('flash.import.no_file_uploaded', domain: 'import'));

            return $this->renderUploadForm($form, $extensions);
        }

        $clientMime = $file->getClientMimeType();
        $clientSize = $file->getSize();

        try {
            $targetPath = $this->fileUploader->upload($file);
        } catch (FileException) {
            $message = $this->translator->trans('validation.import.excel_rejected', [], 'validators');
            $form->get('file')->addError(new FormError($message));

            return $this->renderUploadForm($form, $extensions);
        }

        $import = new Import()
            ->setName($name)
            ->setHospital($managedHospital)
            ->setCreatedBy($createdBy)
            ->setType($importType)
            ->setStatus(ImportStatus::PENDING)
            ->setFilePath($targetPath)
            ->setFileExtension(pathinfo($targetPath, PATHINFO_EXTENSION) ?: 'csv')
            ->setFileMimeType($clientMime)
            ->setFileSize($clientSize)
            ->setRunCount(0)
            ->setRunTime(0)
            ->setRowCount(0)
            ->setHospitalOverride($this->resolveHospitalOverride($form));

        $checksum = $this->checksumCalculator->forPath($targetPath);
        $import->setFileChecksum($checksum);

        $this->em->persist($import);
        $extension?->onImportCreated($import, $form, $user);
        $this->auditContext->beginIntent('import.created', []);
        try {
            $this->em->flush();
        } finally {
            $this->auditContext->endIntent();
        }

        $importId = $import->getId();

        if (null === $importId) {
            throw new \LogicException('Persisted Import entity has no ID.');
        }

        if ($extension instanceof ImportTypeExtensionInterface) {
            // Registered and queued by the type's workflow dispatcher (`import.workflow_dispatcher`).
            $this->startDispatcher->dispatch($importId, $userId, ImportRunTrigger::Upload);
            $this->addFlash('success', new TranslatableMessage('flash.import.created', domain: 'import'));

            return $this->redirectToRoute('app_import_processing', ['id' => $importId]);
        }

        // Registration and message are committed together; a failure leaves neither behind.
        $this->runLifecycle->register(
            $importId,
            ImportRunTrigger::Upload,
            $userId,
            null,
            function (ImportRun $run) use ($importId, $importType, $userId): void {
                $runId = $run->getId()->toRfc4122();
                $this->bus->dispatch(ImportType::CLOSURE === $importType
                    ? new ImportClosuresMessage($importId, $runId, $userId)
                    : new ImportAllocationsMessage($importId, $runId, $userId));
            },
        );

        $this->addFlash('success', new TranslatableMessage('flash.import.created', domain: 'import'));

        return $this->redirectToRoute('app_import_processing', ['id' => $importId]);
    }

    /**
     * @param FormInterface<mixed>               $form
     * @param list<ImportTypeExtensionInterface> $extensions
     */
    private function renderUploadForm(FormInterface $form, array $extensions): Response
    {
        $extensionFields = [];
        foreach ($extensions as $extension) {
            $extensionFields = [...$extensionFields, ...$extension->formFields()];
        }

        return $this->render('@Import/new.html.twig', [
            'form' => $form->createView(),
            'extension_fields' => $extensionFields,
        ]);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function resolveImportType(FormInterface $form): ImportType
    {
        if (!$form->has('type')) {
            return ImportType::ALLOCATION;
        }

        $type = $form->get('type')->getData();

        return $type instanceof ImportType ? $type : ImportType::ALLOCATION;
    }

    /**
     * The explicit development-only target hospital override (#878); never set outside `dev`.
     *
     * @param FormInterface<mixed> $form
     */
    private function resolveHospitalOverride(FormInterface $form): bool
    {
        if (!$this->hospitalOverride->isAvailable() || !$form->has('hospitalOverride')) {
            return false;
        }

        return true === $form->get('hospitalOverride')->getData();
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function rejectUnsupportedImportFile(FormInterface $form): void
    {
        if (!$form->has('file')) {
            return;
        }

        $file = $form->get('file')->getData();
        if (!$file instanceof UploadedFile) {
            return;
        }

        $messageKey = $this->importUploadGuard->resolveRejectionMessageKey($file);
        if (null === $messageKey) {
            return;
        }

        $message = $this->translator->trans($messageKey, [], 'validators');
        $fileField = $form->get('file');

        if (0 === $fileField->getErrors()->count()) {
            $fileField->addError(new FormError($message));
        }
    }
}
