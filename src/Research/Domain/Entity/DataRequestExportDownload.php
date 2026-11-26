<?php

declare(strict_types=1);

namespace App\Research\Domain\Entity;

use App\User\Domain\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * One download of an export archive (ADR 045): who and when. Rows are only added.
 */
#[ORM\Entity]
#[ORM\Table(name: 'data_request_export_download')]
class DataRequestExportDownload
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'downloads')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DataRequestExportRun $run;

    /** Null once the account is deleted. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user;

    #[ORM\Column]
    private \DateTimeImmutable $downloadedAt;

    public function __construct(DataRequestExportRun $run, ?User $user, \DateTimeImmutable $downloadedAt)
    {
        $this->run = $run;
        $this->user = $user;
        $this->downloadedAt = $downloadedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRun(): DataRequestExportRun
    {
        return $this->run;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getDownloadedAt(): \DateTimeImmutable
    {
        return $this->downloadedAt;
    }
}
