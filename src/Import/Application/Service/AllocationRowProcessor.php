<?php

namespace App\Import\Application\Service;

use App\Import\Application\Contracts\AllocationPersisterInterface;
use App\Import\Application\Contracts\AllocationRowProcessorInterface;
use App\Import\Application\Contracts\RowToDtoMapperInterface;
use App\Import\Application\Exception\ImportException;
use App\Import\Application\Exception\RowRejectException;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\AllocationRowType;
use App\Import\Infrastructure\Mapping\AllocationImportFactory;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** @psalm-suppress UnusedClass */
final class AllocationRowProcessor implements AllocationRowProcessorInterface
{
    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly RowToDtoMapperInterface $mapper,
        private readonly AllocationImportFactory $factory,
        private readonly AllocationPersisterInterface $persister,
    ) {
    }

    #[\Override]
    public function type(): AllocationRowType
    {
        return AllocationRowType::ALLOCATION;
    }

    #[\Override]
    public function warm(): void
    {
        $this->factory->warm();
    }

    /**
     * @param array<string,string> $row
     */
    #[\Override]
    public function process(array $row, Import $import, int $lineNo): void
    {
        $dto = $this->mapper->mapAssoc($row);

        $violations = $this->validator->validate($dto);
        if (\count($violations) > 0) {
            $messages = [];

            foreach ($violations as $v) {
                $messages[] = \sprintf('%s: %s', $v->getPropertyPath(), $v->getMessage());
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
