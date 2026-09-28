<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Query;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Import\Domain\Entity\ImportReject;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Query\ListImportRejectsQuery;
use App\Import\UI\Http\DTO\ImportRejectQueryParametersDTO;
use App\Tests\Support\Pagination\PaginatesQueries;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\Pagination\NumberedPaginationInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ListImportRejectsQueryTest extends KernelTestCase
{
    use Factories;
    use PaginatesQueries;

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

        $paginator = $this->paginateRejects(new ImportRejectQueryParametersDTO(
            search: $fixture['messageToken'],
        ));

        self::assertSame(1, $paginator->getTotalItems());
        self::assertSame([$fixture['importName']], $this->extractImportNames($paginator));
    }

    public function testSearchWithoutMatchReturnsEmpty(): void
    {
        $this->seedReject();

        $paginator = $this->paginateRejects(new ImportRejectQueryParametersDTO(
            search: 'no-such-reject-token-'.bin2hex(random_bytes(4)),
        ));

        self::assertSame(0, $paginator->getTotalItems());
        self::assertSame([], $this->extractImportNames($paginator));
    }

    public function testBlankSearchDoesNotFilterByMessages(): void
    {
        $fixture = $this->seedReject();

        $paginator = $this->paginateRejects(new ImportRejectQueryParametersDTO(
            search: '   ',
        ));

        self::assertSame(1, $paginator->getTotalItems());
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
     * @param NumberedPaginationInterface<mixed> $paginator
     *
     * @return list<string>
     */
    private function extractImportNames(NumberedPaginationInterface $paginator): array
    {
        $names = [];
        foreach ($paginator->getItems() as $row) {
            self::assertIsArray($row);
            self::assertArrayHasKey('import_name', $row);
            self::assertIsString($row['import_name']);
            $names[] = $row['import_name'];
        }

        return $names;
    }

    /**
     * @return NumberedPaginationInterface<mixed>
     */
    private function paginateRejects(ImportRejectQueryParametersDTO $query): NumberedPaginationInterface
    {
        return $this->paginateQuery($this->query->listQuery($query));
    }
}
