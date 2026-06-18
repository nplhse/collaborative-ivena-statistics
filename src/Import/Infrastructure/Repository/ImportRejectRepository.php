<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Repository;

use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\ImportReject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportReject>
 */
final class ImportRejectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportReject::class);
    }

    public function deleteByImport(Import $import): int
    {
        return $this->createQueryBuilder('r')
            ->delete()
            ->where('r.import = :import')
            ->setParameter('import', $import, Import::class)
            ->getQuery()
            ->execute();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return iterable<array{
     *     id: int,
     *     lineNumber: ?int,
     *     messages: list<string>,
     *     row: array<string, mixed>,
     *     importName: ?string,
     *     importFilePath: ?string
     * }>
     */
    public function iterateForAnalysis(): iterable
    {
        $query = $this->createQueryBuilder('r')
            ->select(
                'r.id AS id',
                'r.lineNumber AS lineNumber',
                'r.messages AS messages',
                'r.row AS row',
                'i.name AS importName',
                'i.filePath AS importFilePath',
            )
            ->join('r.import', 'i')
            ->orderBy('r.id', 'ASC')
            ->getQuery();

        foreach ($query->toIterable() as $row) {
            /** @var array{
             *     id: int|string,
             *     lineNumber: ?int,
             *     messages: list<string>|string,
             *     row: array<string, mixed>|string,
             *     importName: ?string,
             *     importFilePath: ?string
             * } $row
             */
            $messages = $row['messages'];
            if (\is_string($messages)) {
                $messages = json_decode($messages, true, 512, JSON_THROW_ON_ERROR);
            }

            $rowData = $row['row'];
            if (\is_string($rowData)) {
                $rowData = json_decode($rowData, true, 512, JSON_THROW_ON_ERROR);
            }

            yield [
                'id' => (int) $row['id'],
                'lineNumber' => $row['lineNumber'] ?? null,
                'messages' => \is_array($messages) ? array_values(array_map(strval(...), $messages)) : [],
                'row' => \is_array($rowData) ? $rowData : [],
                'importName' => $row['importName'],
                'importFilePath' => $row['importFilePath'],
            ];
        }
    }
}
