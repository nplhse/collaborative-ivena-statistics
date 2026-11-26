<?php

declare(strict_types=1);

namespace App\Tests\Research\Integration\Repository;

use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\ValueObject\DataRequestContent;
use App\Research\Infrastructure\Repository\DataRequestRepository;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Participants see submitted requests of everyone and only their own drafts, newest first (ADR 045).
 */
#[ResetDatabase]
final class DataRequestRepositoryTest extends KernelTestCase
{
    use Factories;

    public function testTheListShowsSubmittedRequestsAndOnlyTheViewersDrafts(): void
    {
        $viewer = UserFactory::new()->asParticipant()->create();
        $other = UserFactory::new()->asParticipant()->create();

        $this->request($other, 'Other draft', '2026-11-20 10:00', submittedAt: null);
        $this->request($other, 'Other submitted early', '2026-11-01 10:00', submittedAt: '2026-11-02 10:00');
        $this->request($other, 'Other submitted late', '2026-11-03 10:00', submittedAt: '2026-11-10 10:00');
        $this->request($viewer, 'Own draft', '2026-11-05 10:00', submittedAt: null);
        $this->em()->clear();

        $titles = array_map(
            static fn (DataRequest $request): string => $request->getCurrentVersion()->getTitle(),
            $this->repository()->findVisibleTo($viewer),
        );

        // Sorted by first submission, drafts by creation: 10 Nov, 5 Nov, 2 Nov.
        self::assertSame(['Other submitted late', 'Own draft', 'Other submitted early'], $titles);
    }

    public function testRequestsOfTheApplicantComeNewestFirst(): void
    {
        $applicant = UserFactory::new()->asParticipant()->create();
        $this->request($applicant, 'Older', '2026-11-01 10:00', submittedAt: null);
        $this->request($applicant, 'Newer', '2026-11-02 10:00', submittedAt: '2026-11-03 10:00');
        $this->request(UserFactory::new()->asParticipant()->create(), 'Someone else', '2026-11-04 10:00', submittedAt: '2026-11-04 11:00');
        $this->em()->clear();

        $titles = array_map(
            static fn (DataRequest $request): string => $request->getCurrentVersion()->getTitle(),
            $this->repository()->findForRequester($applicant),
        );

        self::assertSame(['Newer', 'Older'], $titles);
    }

    private function request(User $applicant, string $title, string $createdAt, ?string $submittedAt): void
    {
        $request = new DataRequest($applicant, new DataRequestContent(
            title: $title,
            summary: 'Summary',
            institution: 'University',
            enrichmentPlanned: false,
            purpose: 'Purpose',
            periodFrom: new \DateTimeImmutable('2024-01-01'),
            periodTo: new \DateTimeImmutable('2024-12-31'),
            population: 'All allocations',
            variables: 'Month',
            granularity: 'Month',
            recipients: 'Team',
            enrichmentDetails: null,
            singleCases: false,
            description: 'Description',
            dataNeeds: 'Data needs',
            responsiblePersons: 'Dr. A',
            conflictsOfInterest: 'none',
            ethics: 'Not needed',
        ), new \DateTimeImmutable($createdAt));

        if (null !== $submittedAt) {
            $request->setMarking('submitted');
            $request->recordSubmission(new \DateTimeImmutable($submittedAt));
        }

        $this->em()->persist($request);
        $this->em()->flush();
    }

    private function repository(): DataRequestRepository
    {
        return self::getContainer()->get(DataRequestRepository::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
