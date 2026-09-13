<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Query;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Import\Domain\Entity\ImportReject;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Query\ListImportRejectsQuery;
use App\Import\UI\Http\DTO\ImportRejectQueryParametersDTO;
use App\Shared\Infrastructure\Pagination\Paginator;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ListImportRejectsQueryTest extends KernelTestCase
{
    use Factories;

    private ListImportRejectsQuery $query;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = new ListImportRejectsQuery(
            self::getContainer()->get(EntityManagerInterface::class),
        );
    }

    public function testSearchByMessagesJsonFindsReject(): void
    {
        $fixture = $this->seedReject();

        $paginator = $this->query->getPaginator(new ImportRejectQueryParametersDTO(
            search: $fixture['messageToken'],
        ));

        self::assertSame(1, $paginator->getNumResults());
        self::assertSame([$fixture['importName']], $this->extractImportNames($paginator));
    }

    public function testSearchWithoutMatchReturnsEmpty(): void
    {
        $this->seedReject();

        $paginator = $this->query->getPaginator(new ImportRejectQueryParametersDTO(
            search: 'no-such-reject-token-'.bin2hex(random_bytes(4)),
        ));

        self::assertSame(0, $paginator->getNumResults());
        self::assertSame([], $this->extractImportNames($paginator));
    }

    public function testBlankSearchDoesNotFilterByMessages(): void
    {
        $fixture = $this->seedReject();

        $paginator = $this->query->getPaginator(new ImportRejectQueryParametersDTO(
            search: '   ',
        ));

        self::assertSame(1, $paginator->getNumResults());
        self::assertSame([$fixture['importName']], $this->extractImportNames($paginator));
    }

    /**
     * @return array{importName: string, messageToken: string}
     */
    private function seedReject(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $importName = 'reject-query-import-'.$suffix;
        $messageToken = 'reject-query-msg-'.$suffix;

        $user = UserFactory::createOne(['username' => 'reject-query-user-'.$suffix]);
        $import = ImportFactory::createOne([
            'name' => $importName,
            'createdBy' => $user,
            'hospital' => HospitalFactory::createOne(['name' => 'reject-query-hospital-'.$suffix]),
        ]);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $reject = new ImportReject();
        $reject->setImport($import);
        $reject->setLineNumber(7);
        $reject->setMessages([$messageToken.': missing field']);
        $reject->setRow(['line' => 7]);
        $em->persist($reject);
        $em->flush();

        return [
            'importName' => $importName,
            'messageToken' => $messageToken,
        ];
    }

    /**
     * @return list<string>
     */
    private function extractImportNames(Paginator $paginator): array
    {
        $names = [];
        foreach ($paginator->getResults() as $row) {
            self::assertIsArray($row);
            self::assertArrayHasKey('import_name', $row);
            self::assertIsString($row['import_name']);
            $names[] = $row['import_name'];
        }

        return $names;
    }
}
