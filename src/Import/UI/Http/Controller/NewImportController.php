<?php

declare(strict_types=1);

namespace App\Import\UI\Http\Controller;

use App\Allocation\Application\Hospital\UploadAuthority\UploadAuthority;
use App\Allocation\Domain\Entity\Hospital;
use App\Import\Application\Contracts\ImportTypeExtensionInterface;
use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Application\Message\ImportClosuresMessage;
use App\Import\Application\Service\FileChecksumCalculator;
use App\Import\Application\Service\FileUploader;
use App\Import\Application\Service\ImportRunLifecycle;
use App\Import\Application\Service\ImportStartDispatcher;
use App\Import\Application\Service\ImportTypeExtensionRegistry;
use App\Import\Application\Service\ImportUploadGuard;
use App\Import\Application\Service\ImportUploadTargets;
use App\Import\Application\Service\PendingImportUploadStore;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\ImportRun;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Security\Voter\ImportCreateVoter;
use App\Import\UI\Form\ImportCreateType;
use App\Shared\Application\RateLimit\ClientRateLimit;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
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
        private readonly ImportUploadTargets $uploadTargets,
        private readonly ImportRunLifecycle $runLifecycle,
        private readonly ImportTypeExtensionRegistry $typeExtensions,
        private readonly ImportStartDispatcher $startDispatcher,
        private readonly PendingImportUploadStore $pendingUploads,
        private readonly UploadAuthority $uploadAuthority,
    ) {
    }

    public function __invoke(
        Request $request,
        #[Autowire(service: 'limiter.import_create')]
        RateLimiterFactory $importCreateLimiter,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        // Without any hospital to upload to, the form could not be submitted (#1351).
        if (!$this->isGranted(ImportCreateVoter::CREATE)) {
            $this->addFlash('info', new TranslatableMessage('flash.import.no_upload_permission', domain: 'import'));

            return $this->redirectToRoute('app_import_index');
        }

        $uploadTargets = $this->uploadTargets->hospitalIdsByType($user);
        $extensions = array_values(array_filter(
            $this->typeExtensions->availableTo($user),
            static fn (ImportTypeExtensionInterface $extension): bool => \array_key_exists($extension::importType()->value, $uploadTargets),
        ));
        $form = $this->createForm(ImportCreateType::class, null, [
            'upload_targets' => $uploadTargets,
            'type_extensions' => $extensions,
        ]);
        $form->handleRequest($request);

        // Remember-Me sessions are enough to start an import; do not re-add
        // IS_AUTHENTICATED_FULLY here (see #568). ROLE_PARTICIPANT and the per-hospital
        // upload rights (ImportUploadTargets) remain the authorization boundary.

        if ($form->isSubmitted()) {
            $this->rejectUnsupportedImportFile($form);
        }

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderUploadForm($form, $extensions);
        }

        /** @var Hospital $hospital */
        $hospital = $form->get('hospital')->getData();
        $hospitalId = $hospital->getId();
        $importType = $this->resolveImportType($form);
        $extension = $this->typeExtensions->for($importType);
        if (null === $hospitalId) {
            throw $this->createAccessDeniedException();
        }

        // Hospital and type are each valid choices, but the user may not upload this type there.
        if (!$this->uploadTargets->canUpload($user, $importType, $hospitalId)) {
            $form->get('hospital')->addError(new FormError($this->translator->trans('validation.import.hospital_type_not_allowed', [], 'validators')));

            return $this->renderUploadForm($form, $extensions);
        }

        // The authority to upload for this hospital, once per hospital and terms version (#1565).
        $confirmsAuthority = $this->uploadAuthority->isRequired($user, $hospital);
        if ($confirmsAuthority && (!$form->has(ImportCreateType::FIELD_UPLOAD_AUTHORITY) || true !== $form->get(ImportCreateType::FIELD_UPLOAD_AUTHORITY)->getData())) {
            $form->get($form->has(ImportCreateType::FIELD_UPLOAD_AUTHORITY) ? ImportCreateType::FIELD_UPLOAD_AUTHORITY : 'hospital')
                ->addError(new FormError($this->translator->trans('validation.import.upload_authority_required', [], 'validators')));

            return $this->renderUploadForm($form, $extensions);
        }

        if ($extension instanceof ImportTypeExtensionInterface) {
            $errors = $extension->validateUpload($form, $hospital, $user);
            foreach ($errors as $field => $message) {
                $form->get($form->has($field) ? $field : 'file')->addError(new FormError($message));
            }

            if ([] !== $errors) {
                return $this->renderUploadForm($form, $extensions);
            }
        }

        $userId = $user->getId();
        if (null === $userId) {
            throw new \LogicException('Authenticated user has no ID.');
        }

        if (!ClientRateLimit::acceptUserAndIp($importCreateLimiter, 'import_create', (string) $userId, $request)) {
            $this->addFlash('warning', new TranslatableMessage('flash.import.rate_limited', domain: 'import'));

            return $this->redirectToRoute('app_import_new');
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

        $uploadToken = (string) $form->get('uploadToken')->getData();
        // A background upload (#977) is consumed by moving it; a second submission of the same token finds no file.
        if ('' !== $uploadToken && !is_file($file->getPathname())) {
            $form->get('file')->addError(new FormError($this->translator->trans('validation.import.upload_consumed', [], 'validators')));

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
            ->setHospitalOverride($this->resolveHospitalOverride($form, $hospital));

        if ('' !== $uploadToken) {
            $this->pendingUploads->delete($uploadToken, $userId);
        }

        $checksum = $this->checksumCalculator->forPath($targetPath);
        $import->setFileChecksum($checksum);

        $this->em->persist($import);
        if ($confirmsAuthority) {
            $this->uploadAuthority->confirm($createdBy, $managedHospital, $request->getLocale());
        }
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

        // After a validation error the background upload stays and the form shows it instead of asking again.
        $user = $this->getUser();
        $uploadToken = $form->has('uploadToken') ? (string) $form->get('uploadToken')->getData() : '';
        $pendingUpload = '' !== $uploadToken && $user instanceof User
            ? $this->pendingUploads->find($uploadToken, (int) $user->getId())
            : null;

        return $this->render('@Import/new.html.twig', [
            'form' => $form->createView(),
            'pending_upload' => $pendingUpload,
            'upload_csrf_token_id' => PendingImportUploadController::CSRF_TOKEN_ID,
            'extension_fields' => $extensionFields,
            'extension_types' => array_map(static fn (ImportTypeExtensionInterface $extension): string => $extension::importType()->value, $extensions),
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
     * The explicit target hospital override (#878, #1352): only where the voter grants it for this hospital.
     *
     * @param FormInterface<mixed> $form
     */
    private function resolveHospitalOverride(FormInterface $form, Hospital $hospital): bool
    {
        if (!$form->has('hospitalOverride') || !$this->isGranted(ImportCreateVoter::OPTION_HOSPITAL_OVERRIDE, $hospital)) {
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
