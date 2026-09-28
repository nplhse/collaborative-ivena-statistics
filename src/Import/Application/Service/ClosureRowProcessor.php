<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Application\Exception\ImportException;
use App\Import\Application\Exception\RowRejectException;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Adapter\DoctrineClosureIntervalPersister;
use App\Import\Infrastructure\Mapping\ClosureImportFactory;
use App\Import\Infrastructure\Mapping\ClosureRowMapper;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class ClosureRowProcessor
{
    public function __construct(
        private ValidatorInterface $validator,
        private ClosureRowMapper $mapper,
        private ClosureImportFactory $factory,
        private DoctrineClosureIntervalPersister $persister,
    ) {
    }

    public function warm(): void
    {
        $this->factory->warm();
    }

    /**
     * @param array<string, string> $row
     *
     * @throws RowRejectException
     */
    public function process(array $row, Import $import): void
    {
        $dto = $this->mapper->mapAssoc($row);
        $violations = $this->validator->validate($dto);
        if (\count($violations) > 0) {
            $messages = [];
            foreach ($violations as $violation) {
                $messages[] = \sprintf('%s: %s', $violation->getPropertyPath(), $violation->getMessage());
            }

            throw new RowRejectException($messages);
        }

        try {
            $entity = $this->factory->fromDto($dto, $import);
            $this->persister->persist($entity);
        } catch (ImportException $e) {
            throw new RowRejectException(messages: [$e->summarize()], context: $e->context());
        }
    }
}
