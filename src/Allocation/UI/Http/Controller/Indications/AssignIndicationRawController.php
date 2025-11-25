<?php

namespace App\Allocation\UI\Http\Controller\Indications;

use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Infrastructure\Repository\IndicationNormalizedRepository;
use App\Allocation\UI\Form\IndicationRawAssignType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/explore/indication/raw/assign')]
final class AssignIndicationRawController extends AbstractController
{
    public function __construct(
        private IndicationNormalizedRepository $repository,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('/{id}', name: 'app_explore_indication_raw_assign', methods: ['GET', 'POST'])]
    public function edit(IndicationRaw $raw, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(IndicationRawAssignType::class, $raw);
        $form->handleRequest($request);

        $datalist = $this->repository->getDatalist();

        $initialId = $form->get('target')->getData();
        $initialLabel = null;

        if (is_string($initialId) && '' !== $initialId && ctype_digit($initialId)) {
            $initialLabel = $this->repository->getDatalistLabelById((int) $initialId);
        } elseif ($initialId instanceof IndicationNormalized) {
            $id = $initialId->getId();

            if (null !== $id) {
                $initialLabel = $this->repository->getDatalistLabelById($id);
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', $this->translator->trans('flash.indication.assigned'));

            return $this->redirectToRoute('app_explore_indication_list', ['type' => 'raw']);
        }

        return $this->render('@Allocation/indications/assign_raw.html.twig', [
            'raw' => $raw,
            'form' => $form->createView(),
            'datalist' => $datalist,
            'initial_label' => $initialLabel,
        ]);
    }
}
