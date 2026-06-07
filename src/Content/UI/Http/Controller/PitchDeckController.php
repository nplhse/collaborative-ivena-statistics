<?php

declare(strict_types=1);

namespace App\Content\UI\Http\Controller;

use App\Allocation\Infrastructure\Repository\AllocationRepository;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Import\Infrastructure\Repository\ImportRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PitchDeckController extends AbstractController
{
    public function __construct(
        #[Autowire(param: 'app.pitch_deck.enabled')]
        private readonly bool $pitchDeckEnabled,
        private readonly HospitalRepository $hospitalRepository,
        private readonly ImportRepository $importRepository,
        private readonly AllocationRepository $allocationRepository,
        #[Autowire(param: 'app.pitch_deck.contact.name')]
        private readonly string $contactName,
        #[Autowire(param: 'app.pitch_deck.contact.institution')]
        private readonly string $contactInstitution,
        #[Autowire(param: 'app.pitch_deck.contact.email')]
        private readonly string $contactEmail,
    ) {
    }

    public function __invoke(): Response
    {
        if (!$this->pitchDeckEnabled) {
            throw new NotFoundHttpException();
        }

        return $this->render('@Content/pitch/deck.html.twig', [
            'hospitalCount' => $this->hospitalRepository->countParticipating(),
            'importCount' => $this->importRepository->count(),
            'allocationCount' => $this->allocationRepository->count(),
            'contactName' => $this->contactName,
            'contactInstitution' => $this->contactInstitution,
            'contactEmail' => $this->contactEmail,
        ]);
    }
}
