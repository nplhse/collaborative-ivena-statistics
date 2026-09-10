<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\ReferenceCatalog;

use App\Allocation\Domain\Entity\Department;
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
                yield $this->row(1, 'REF_NOT_FOUND | field=department | value="Perinatalzentrum Level 2"', [
                    'fachbereich' => 'Perinatalzentrum Level 2',
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
        self::assertSame(['Göttingen'], array_column($all->document->dispatchAreas, 'name'));
        self::assertContains('aus Klinik', $all->document->occasions);
        self::assertNotContains('Häuslicher Einsatz', $all->document->occasions);
        self::assertNotContains('Öffentlicher Raum', $all->document->occasions);
        self::assertNotContains("H\u{0084}uslicher Einsatz", $all->document->occasions);
        self::assertContains('ECMO-Therapie', $all->document->specialities);
        self::assertContains('NAW', $all->document->assignments);
        self::assertContains('MRSA', $all->document->infections);
        self::assertContains('Verlegung KH', $all->document->secondaryTransports);
        self::assertSame([], $all->document->departments);
        self::assertNotContains('Anlass Ã¤', $all->document->occasions);
        self::assertSame([1, 2], $all->importIds);
        self::assertGreaterThan(0, $all->droppedAsJunk);
        self::assertGreaterThan(0, $all->droppedAsKnown);

        $filtered = $proposer->propose(minCount: 2);
        self::assertSame([], $filtered->document->specialities);
        self::assertContains('Göttingen', array_column($filtered->document->dispatchAreas, 'name'));
    }
}
