<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AssignmentDistributionNameMapper implements ValueMapper
{
    /** @var array<int, string> */
    private array $cache = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function label(?int $value): string
    {
        if (null === $value || $value <= 0) {
            return $this->translator->trans('statistics.distribution.assignment.invalid_id');
        }

        if (!\array_key_exists($value, $this->cache)) {
            $name = $this->connection->fetchOne(
                'SELECT name FROM assignment WHERE id = :id',
                ['id' => $value],
            );
            $this->cache[$value] = \is_string($name) && '' !== $name
                ? $name
                : $this->translator->trans('statistics.distribution.entity_name_missing', ['id' => $value]);
        }

        return $this->cache[$value];
    }
}
