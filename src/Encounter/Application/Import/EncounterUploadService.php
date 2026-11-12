<?php

declare(strict_types=1);

namespace App\Encounter\Application\Import;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\EncounterKeyStateReaderInterface;
use App\Encounter\Domain\Entity\EncounterImport;
use App\Import\Application\Service\FileChecksumCalculator;
use App\Import\Application\Service\FileUploader;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stores an uploaded encounter file as an import of type ENCOUNTER and queues its first run (#1048).
 *
 * The confirmation is stored with user and time and audited (`encounter.import.created`); the key
 * generation of the hospital at upload is stored for the commit checks (ADR 026). The caller checks
 * EncounterVoter::UPLOAD and refuses uploads while a key change is in progress.
 */
final readonly class EncounterUploadService
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FileUploader $fileUploader,
        private FileChecksumCalculator $checksumCalculator,
        private EncounterKeyStateReaderInterface $keyStates,
        private ImportEncountersDispatcher $dispatcher,
        private AuditContext $auditContext,
        private ClockInterface $clock,
    ) {
    }

    public function isKeyChangeInProgress(Hospital $hospital): bool
    {
        return $this->keyStates->currentState((int) $hospital->getId())['key_change_in_progress'];
    }

    /**
     * @throws \Symfony\Component\HttpFoundation\File\Exception\FileException when the file cannot be stored
     */
    public function upload(Hospital $hospital, User $user, string $name, string $schema, UploadedFile $file): Import
    {
        $hospitalId = (int) $hospital->getId();
        $userId = (int) $user->getId();
        $clientMime = $file->getClientMimeType();
        $clientSize = (int) $file->getSize();
        $storedPath = $this->fileUploader->upload($file);

        /** @var Hospital $hospitalReference */
        $hospitalReference = $this->entityManager->getReference(Hospital::class, $hospitalId);
        /** @var User $userReference */
        $userReference = $this->entityManager->getReference(User::class, $userId);

        $import = new Import()
            ->setName($name)
            ->setHospital($hospitalReference)
            ->setCreatedBy($userReference)
            ->setType(ImportType::ENCOUNTER)
            ->setStatus(ImportStatus::PENDING)
            ->setFilePath($storedPath)
            ->setFileExtension(pathinfo($storedPath, \PATHINFO_EXTENSION) ?: 'csv')
            ->setFileMimeType($clientMime)
            ->setFileSize($clientSize)
            ->setFileChecksum($this->checksumCalculator->forPath($storedPath))
            ->setRunCount(0)
            ->setRunTime(0)
            ->setRowCount(0);

        $confirmedAt = $this->clock->now();
        $generation = $this->keyStates->currentState($hospitalId)['generation'];
        $encounterImport = new EncounterImport($import, $schema, $generation, $userReference, $confirmedAt);

        $this->entityManager->persist($import);
        $this->entityManager->persist($encounterImport);
        $this->auditContext->beginIntent('encounter.import.created', [
            'hospital_id' => $hospitalId,
            'schema' => $schema,
            'key_generation' => $generation,
            'confirmed_by' => $userId,
            'confirmed_at' => $confirmedAt->format(\DATE_ATOM),
        ]);
        try {
            $this->entityManager->flush();
        } finally {
            $this->auditContext->endIntent();
        }

        $this->dispatcher->dispatch((int) $import->getId(), ImportRunTrigger::Upload, $userId);

        return $import;
    }
}
