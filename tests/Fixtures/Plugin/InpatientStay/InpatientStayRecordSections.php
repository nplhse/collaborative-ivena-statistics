<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Plugin\InpatientStay;

use App\Encounter\Contract\Display\RecordSection;
use App\Encounter\Contract\Display\RecordSectionProviderInterface;
use App\Tests\Fixtures\Plugin\InpatientStay\Entity\InpatientStay;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The stays attached to an encounter, in the hospital course card of the case file (one query).
 */
final readonly class InpatientStayRecordSections implements RecordSectionProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function sectionsForEncounter(int $encounterId): array
    {
        $stays = $this->entityManager->getRepository(InpatientStay::class)->findBy(['encounterId' => $encounterId], ['admittedAt' => 'ASC']);

        return [] === $stays ? [] : [new RecordSection('inpatient_stay', '@InpatientStayTest/record_section.html.twig', ['stays' => $stays])];
    }
}
