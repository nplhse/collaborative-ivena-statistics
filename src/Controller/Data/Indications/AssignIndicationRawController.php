<?php

namespace App\Controller\Data\Indications;

use App\Entity\IndicationRaw;
use App\Form\IndicationRawAssignType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/data/indication/raw/assign')]
final class AssignIndicationRawController extends AbstractController
{
    #[Route('/{id}', name: 'app_data_indication_raw_assign', methods: ['GET', 'POST'])]
    public function edit(IndicationRaw $raw, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(IndicationRawAssignType::class, $raw);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Zuordnung gespeichert.');

            return $this->redirectToRoute('app_data_indication_list', ['type' => 'raw']);
        }

        return $this->render('data/indications/assign_raw.html.twig', [
            'raw' => $raw,
            'form' => $form->createView(),
        ]);
    }
}
