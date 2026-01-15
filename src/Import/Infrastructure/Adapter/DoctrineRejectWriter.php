<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Adapter;

use App\Import\Application\Contracts\RejectWriterInterface;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\ImportReject;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem('import.reject_writer')]
final class DoctrineRejectWriter implements RejectWriterInterface
{
    private ?Import $import = null;
    private int $count = 0;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function getType(): string
    {
        return 'db';
    }

    #[\Override]
    public function start(Import $import): void
    {
        $this->import = $import;
        $this->count = 0;
    }

    #[\Override]
    public function write(array $row, array $messages, ?int $line = null): void
    {
        if (null === $this->import) {
            throw new \LogicException('Reject writer not started. Call start() before write().');
        }

        $normRow = [];
        foreach ($row as $k => $v) {
            $normRow[$k] = $v;
        }

        $reject = new ImportReject();
        $reject->setImport($this->import);
        $reject->setLineNumber($line);
        $reject->setMessages($messages);
        $reject->setRow($normRow);

        $this->em->persist($reject);

        ++$this->count;
    }

    #[\Override]
    public function close(): void
    {
        $this->em->flush();
    }

    #[\Override]
    public function getCount(): int
    {
        return $this->count;
    }

    #[\Override]
    public function getPath(): ?string
    {
        return null;
    }
}
