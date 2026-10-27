<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\ReferenceCatalog;

use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\DepartmentAlias;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Occasion;
use App\Import\Application\Analysis\ImportRejectAnalysisReader;
use App\Import\Application\Analysis\RejectMessageNormalizer;
use App\Import\Application\Mapping\DispatchAreaNameNormalizer;
use App\Import\Application\ReferenceCatalog\ReferenceCatalogFromRejectsProposer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class ReferenceCatalogFromRejectsProposerTest extends TestCase
{
    public function testDropsJunkKnownAliasesAndRespectsMinCount(): void
    {
        $reader = new class implements ImportRejectAnalysisReader {
            public function iterateForAnalysis(): iterable
            {
                yield $this->row(1, 'REF_NOT_FOUND | field=dispatchArea | value="_Kommunale Regionalleitstelle Göttingen"', [
                    'zuweisung_durch' => '_Kommunale Regionalleitstelle Göttingen',
                    'anlass' => 'aus Klinik',
                    'ansteckungsfaehig' => 'Keine',
                    'sekundaeranlass' => 'https://smed.health/#/',
                ]);
                yield $this->row(1, 'REF_NOT_FOUND | field=dispatchArea | value="_Kommunale Regionalleitstelle Göttingen"', [
                    'zuweisung_durch' => '_Kommunale Regionalleitstelle Göttingen',
                ]);
                yield $this->row(1, 'REF_NOT_FOUND | field=department | value="Geburtszentrum B"', [
                    'fachbereich' => 'Geburtszentrum B',
                ]);
                yield $this->row(2, 'REF_NOT_FOUND | field=speciality | value="ECMO-Therapie"', [
                    'fachgebiet' => 'ECMO-Therapie',
                    'anlass' => 'Erhängen',
                ]);
                yield $this->row(2, 'REF_NOT_FOUND | field=assignment | value="NAW"', [
                    'zuweisung_durch' => 'NAW',
                ]);
                yield $this->row(2, 'REF_NOT_FOUND | field=infection | value="MRSA"', [
                    'ansteckungsfaehig' => 'MRSA',
                ]);
                yield $this->row(2, 'REF_NOT_FOUND | field=secondaryTransport | value="Verlegung KH"', [
                    'sekundaeranlass' => 'Verlegung KH',
                ]);
                yield $this->row(2, 'REF_NOT_FOUND | field=hospital | value="Whatever"', []);
                yield $this->row(2, 'REF_NOT_FOUND | field=department | value="(empty)"', []);
                yield $this->row(2, 'REF_NOT_FOUND | field=dispatchArea | value="___"', [
                    'zuweisung_durch' => '___',
                ]);
                yield $this->row(2, 'REF_NOT_FOUND | field=occasion | value="Anlass Ã¤"', [
                    'anlass' => 'Anlass Ã¤',
                ]);
                yield $this->row(2, 'createdAt: This value should not be blank.', [
                    'anlass' => '',
                ]);
                yield $this->row(2, "REF_NOT_FOUND | field=occasion | value=\"H\u{0084}uslicher Einsatz\"", [
                    'anlass' => "H\u{0084}uslicher Einsatz",
                ]);
                yield $this->row(2, "REF_NOT_FOUND | field=occasion | value=\"\u{0099}ffentlicher Raum\"", [
                    'anlass' => "\u{0099}ffentlicher Raum",
                ]);
            }

            /**
             * @param array<string, mixed> $row
             *
             * @return array{
             *     id: int,
             *     lineNumber: ?int,
             *     messages: list<string>,
             *     row: array<string, mixed>,
             *     importId: ?int,
             *     importName: ?string,
             *     importFilePath: ?string,
             *     hospitalName: ?string
             * }
             */
            private function row(int $importId, string $message, array $row): array
            {
                return [
                    'id' => $importId,
                    'lineNumber' => 1,
                    'messages' => [$message],
                    'row' => $row,
                    'importId' => $importId,
                    'importName' => 'import-'.$importId,
                    'importFilePath' => 'var/imports/'.$importId.'.csv',
                    'hospitalName' => 'KH',
                ];
            }
        };

        $geburtshilfe = $this->createStub(Department::class);
        $geburtshilfe->method('getName')->willReturn('Geburtshilfe');
        $frankfurt = $this->createStub(DispatchArea::class);
        $frankfurt->method('getName')->willReturn('Frankfurt');
        $haeuslich = $this->createStub(Occasion::class);
        $haeuslich->method('getName')->willReturn('Häuslicher Einsatz');
        $oeffentlich = $this->createStub(Occasion::class);
        $oeffentlich->method('getName')->willReturn('Öffentlicher Raum');

        $departmentRepo = $this->createStub(EntityRepository::class);
        $departmentRepo->method('findBy')->willReturn([$geburtshilfe]);
        $alias = $this->createStub(DepartmentAlias::class);
        $alias->method('getNormalizedName')->willReturn('geburtszentrum b');
        $aliasRepo = $this->createStub(EntityRepository::class);
        $aliasRepo->method('findBy')->willReturn([$alias, new \stdClass()]);
        $dispatchRepo = $this->createStub(EntityRepository::class);
        $dispatchRepo->method('findBy')->willReturn([$frankfurt]);
        $occasionRepo = $this->createStub(EntityRepository::class);
        $occasionRepo->method('findBy')->willReturn([$haeuslich, $oeffentlich]);
        $emptyRepo = $this->createStub(EntityRepository::class);
        $emptyRepo->method('findBy')->willReturn([]);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            static fn (string $class): EntityRepository => match ($class) {
                Department::class => $departmentRepo,
                DepartmentAlias::class => $aliasRepo,
                DispatchArea::class => $dispatchRepo,
                Occasion::class => $occasionRepo,
                default => $emptyRepo,
            },
        );

        $proposer = new ReferenceCatalogFromRejectsProposer(
            $reader,
            new RejectMessageNormalizer(),
            new DispatchAreaNameNormalizer(),
            $em,
        );

        $all = $proposer->propose(minCount: 1);
        $values = $this->valuesByType($all->entries);
        self::assertSame(['Göttingen'], $values['dispatch-area'] ?? []);
        self::assertContains('aus Klinik', $values['occasion'] ?? []);
        self::assertNotContains('Häuslicher Einsatz', $values['occasion'] ?? []);
        self::assertNotContains('Öffentlicher Raum', $values['occasion'] ?? []);
        self::assertNotContains("H\u{0084}uslicher Einsatz", $values['occasion'] ?? []);
        self::assertContains('ECMO-Therapie', $values['speciality'] ?? []);
        self::assertContains('NAW', $values['assignment'] ?? []);
        self::assertContains('MRSA', $values['infection'] ?? []);
        self::assertContains('Verlegung KH', $values['secondary-transport'] ?? []);
        self::assertArrayNotHasKey('department', $values);
        self::assertNotContains('Anlass Ã¤', $values['occasion'] ?? []);
        self::assertSame([1, 2], $all->importIds);
        self::assertGreaterThan(0, $all->droppedAsJunk);
        self::assertGreaterThan(0, $all->droppedAsKnown);

        $filtered = $proposer->propose(minCount: 2);
        $filteredValues = $this->valuesByType($filtered->entries);
        self::assertArrayNotHasKey('speciality', $filteredValues);
        self::assertContains('Göttingen', $filteredValues['dispatch-area'] ?? []);
    }

    /**
     * @param list<array{type: string, value: string, count: int, exampleFile: string}> $entries
     *
     * @return array<string, list<string>>
     */
    private function valuesByType(array $entries): array
    {
        $values = [];
        foreach ($entries as $entry) {
            $values[$entry['type']][] = $entry['value'];
        }

        return $values;
    }
}
