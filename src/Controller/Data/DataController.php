<?php

namespace App\Controller\Data;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/data', name: 'app_data_index')]
final class DataController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('data/index.html.twig', []);
    }
}
