<?php

namespace App\Controller\Data;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DataController extends AbstractController
{
    #[Route('/data', name: 'app_data_index')]
    public function index(): Response
    {
        return $this->render('data/index.html.twig', []);
    }
}
