<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Import;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\EncounterUploadHospitalsInterface;
use App\Encounter\Application\Dimension\EncounterDimensionRegistry;
use App\Encounter\Application\Import\EncounterUploadService;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\UI\Form\EncounterImportType;
use App\Shared\Application\RateLimit\ClientRateLimit;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Upload of a canonical encounter exchange file (#1048): beta role plus EncounterVoter::UPLOAD on the
 * chosen hospital; refused while the hospital's key change is in progress (ADR 026).
 */
#[IsGranted(UserRole::ENCOUNTER_BETA)]
final class NewEncounterImportController extends AbstractController
{
    public function __construct(
        private readonly EncounterUploadHospitalsInterface $uploadHospitals,
        private readonly EncounterDimensionRegistry $dimensions,
        private readonly EncounterUploadService $uploadService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/encounter/imports/new', name: 'app_encounter_import_new', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request,
        #[Autowire(service: 'limiter.import_create')]
        RateLimiterFactory $importCreateLimiter,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $hospitals = array_values(array_filter(
            $this->uploadHospitals->forUser($user, $this->isGranted(UserRole::ADMIN)),
            fn (Hospital $hospital): bool => $this->isGranted(EncounterAttribute::UPLOAD, $hospital),
        ));
        if ([] === $hospitals) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(EncounterImportType::class, null, [
            'hospitals' => $hospitals,
            'schemas' => $this->dimensions->schemas(),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('@Encounter/imports/new.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
        }

        /** @var Hospital $hospital */
        $hospital = $form->get('hospital')->getData();
        $this->denyAccessUnlessGranted(EncounterAttribute::UPLOAD, $hospital);

        if ($this->uploadService->isKeyChangeInProgress($hospital)) {
            $form->get('hospital')->addError(new FormError($this->translator->trans('encounter.import.key_change_in_progress', [], 'encounter')));

            return $this->render('@Encounter/imports/new.html.twig', ['form' => $form], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        if (!ClientRateLimit::acceptUserAndIp($importCreateLimiter, 'import_create', (string) $user->getId(), $request)) {
            $this->addFlash('warning', new TranslatableMessage('flash.import.rate_limited', domain: 'import'));

            return $this->redirectToRoute('app_encounter_import_new');
        }

        /** @var UploadedFile $file */
        $file = $form->get('file')->getData();

        try {
            $import = $this->uploadService->upload($hospital, $user, (string) $form->get('name')->getData(), (string) $form->get('schema')->getData(), $file);
        } catch (FileException) {
            $form->get('file')->addError(new FormError($this->translator->trans('validation.import.excel_rejected', [], 'validators')));

            return $this->render('@Encounter/imports/new.html.twig', ['form' => $form], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $this->addFlash('success', new TranslatableMessage('encounter.import.flash.created', domain: 'encounter'));

        return $this->redirectToRoute('app_encounter_import_show', ['id' => $import->getId()]);
    }
}
