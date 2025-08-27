<?php

namespace App\Controller\Import;

use App\Entity\Hospital;
use App\Entity\Import;
use App\Enum\ImportStatus;
use App\Enum\ImportType;
use App\Form\ImportCreateType;
use App\Message\ImportAllocationsMessage;
use App\Service\Import\FileChecksumCalculator;
use App\Service\Import\FileUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/import/new', name: 'app_import_new', methods: ['GET', 'POST'])]
final class NewImportController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly FileChecksumCalculator $checksumCalculator,
        private readonly FileUploader $fileUploader,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $form = $this->createForm(ImportCreateType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('import/new.html.twig', [
                'form' => $form->createView(),
            ]);
        }

        /** @var Hospital $hospital */
        $hospital = $form->get('hospital')->getData();
        $name = (string) $form->get('name')->getData();

        /** @var UploadedFile $file */
        $file = $form->get('file')->getData();

        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', 'flash.import.no_file_uploaded');

            return $this->render('import/new.html.twig', [
                'form' => $form->createView(),
            ]);
        }

        $clientMime = $file->getClientMimeType();
        $clientSize = $file->getSize();
        $targetAbs = $this->fileUploader->upload($file);

        $import = new Import()
            ->setName($name)
            ->setHospital($hospital)
            ->setType(ImportType::ALLOCATION)
            ->setStatus(ImportStatus::PENDING)
            ->setFilePath($targetAbs)
            ->setFileExtension(pathinfo($targetAbs, PATHINFO_EXTENSION) ?: 'csv')
            ->setFileMimeType($clientMime)
            ->setFileSize($clientSize)
            ->setRunCount(0)
            ->setRunTime(0)
            ->setRowCount(0);

        $checksum = $this->checksumCalculator->forPath($targetAbs);
        $import->setFileChecksum($checksum);

        $this->em->persist($import);
        $this->em->flush();

        $importId = $import->getId();

        if (null === $importId) {
            throw new \LogicException('Persisted Import entity has no ID.');
        }

        $this->bus->dispatch(new ImportAllocationsMessage($importId));

        $this->addFlash('success', 'flash.import.created');

        return $this->redirectToRoute('app_import_show', ['id' => $importId]);
    }
}
