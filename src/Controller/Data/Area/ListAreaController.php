<?php

namespace App\Controller\Data\Area;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ListAreaController extends AbstractController
{
    #[Route('/data/area', name: 'app_data_area_list')]
    public function index(): Response
    {
        return $this->render('data/area/list.html.twig');
    }
}
