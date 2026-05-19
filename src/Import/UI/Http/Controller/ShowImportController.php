<?php

declare(strict_types=1);

namespace App\Import\UI\Http\Controller;

use App\Import\Application\Service\ImportListAccess;
use App\Import\Domain\Entity\Import;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ShowImportController extends AbstractController
{
    public function __construct(
        private readonly ImportListAccess $importListAccess,
    ) {
    }

    #[Route('/import/{id}', name: 'app_import_show')]
    public function __invoke(Import $import): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException('You must be logged in to view imports.');
        }

        $hospitalId = $import->getHospital()?->getId();
        if (null === $hospitalId || !$this->importListAccess->canAccessImportHospital($user, $hospitalId)) {
            throw new AccessDeniedException('You do not have access to this import.');
        }

        return $this->render('@Import/show.html.twig', [
            'import' => $import,
        ]);
    }
}
