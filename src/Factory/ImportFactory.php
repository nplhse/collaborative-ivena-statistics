<?php

namespace App\Factory;

use App\Entity\Import;
use App\Enum\ImportStatus;
use App\Enum\ImportType;
use App\User\Domain\Factory\UserFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<Import>
 */
final class ImportFactory extends PersistentProxyObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return Import::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        return [
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTimeThisDecade()),
            'createdBy' => UserFactory::random(),
            'fileExtension' => self::faker()->randomElement(['.csv']),
            'fileMimeType' => self::faker()->randomElement(['text/csv', 'text/plain']),
            'filePath' => 'dummy/path',
            'fileSize' => self::faker()->numberBetween(1, 100000),
            'hospital' => HospitalFactory::random(),
            'name' => self::faker()->sentence(5),
            'rowCount' => self::faker()->numberBetween(10, 2500),
            'runCount' => self::faker()->numberBetween(1, 10),
            'runTime' => self::faker()->numberBetween(100, 2000),
            'status' => self::faker()->randomElement(ImportStatus::cases()),
            'type' => self::faker()->randomElement(ImportType::cases()),
        ];
    }
}
