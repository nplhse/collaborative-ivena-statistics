<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/', name: 'app_default')]
final class DefaultController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('default/index.html.twig');
    }
}
