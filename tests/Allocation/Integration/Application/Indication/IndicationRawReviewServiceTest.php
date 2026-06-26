<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Application\Indication;

use App\Allocation\Application\Indication\IndicationRawReviewService;
use App\Allocation\Domain\Enum\IndicationRawReviewStatus;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Repository\IndicationRawRepository;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class IndicationRawReviewServiceTest extends KernelTestCase
{
    use Factories;

    private IndicationRawReviewService $service;

    private IndicationRawRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->service = self::getContainer()->get(IndicationRawReviewService::class);
        $this->repository = self::getContainer()->get(IndicationRawRepository::class);
    }

    public function testProposeMatchDoesNotOverwriteExistingFirstMatcher(): void
    {
        $firstMatcher = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS],
        ]);
        $secondMatcher = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS],
        ]);
        $normalized = IndicationNormalizedFactory::createOne(['code' => 701, 'name' => 'Propose Norm']);
        $raw = IndicationRawFactory::createOne([
            'code' => 701,
            'name' => 'Propose Raw',
            'firstMatchedBy' => $firstMatcher,
            'firstMatchedAt' => new \DateTimeImmutable('-1 day'),
        ]);

        $this->login($secondMatcher);
        $this->service->proposeMatch($raw, $normalized, $secondMatcher);

        $reloaded = $this->repository->find($raw->getId());
        self::assertNotNull($reloaded);
        self::assertSame($firstMatcher->getId(), $reloaded->getFirstMatchedBy()?->getId());
        self::assertSame(IndicationRawReviewStatus::NeedsReview, $reloaded->getReviewStatus());
    }

    public function testRejectMatchClearsAssignmentAndResetsStatus(): void
    {
        $proposer = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS],
        ]);
        $reviewer = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS],
        ]);
        $normalized = IndicationNormalizedFactory::createOne(['code' => 702, 'name' => 'Reject Norm']);
        $raw = IndicationRawFactory::createOne([
            'code' => 702,
            'name' => 'Reject Raw',
            'target' => $normalized,
            'firstMatchedBy' => $proposer,
            'firstMatchedAt' => new \DateTimeImmutable(),
            'reviewStatus' => IndicationRawReviewStatus::NeedsReview,
        ]);

        $this->login($reviewer);
        $this->service->rejectMatch($raw, $reviewer, 'Not a fit');

        $reloaded = $this->repository->find($raw->getId());
        self::assertNotNull($reloaded);
        self::assertSame(IndicationRawReviewStatus::Unreviewed, $reloaded->getReviewStatus());
        self::assertNull($reloaded->getTarget());
        self::assertSame('Not a fit', $reloaded->getReviewComment());
        self::assertSame($reviewer->getId(), $reloaded->getReviewedBy()?->getId());
    }

    public function testReviewNotMatchableAndIgnoreCloseWithoutMatch(): void
    {
        $reviewer = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS],
        ]);
        $notMatchableRaw = IndicationRawFactory::createOne(['code' => 703, 'name' => 'NM Raw']);
        $ignoredRaw = IndicationRawFactory::createOne(['code' => 704, 'name' => 'Ignored Raw']);

        $this->login($reviewer);
        $this->service->reviewNotMatchable($notMatchableRaw, $reviewer);
        $this->service->reviewIgnore($ignoredRaw, $reviewer, 'Low value');

        $reloadedNotMatchable = $this->repository->find($notMatchableRaw->getId());
        $reloadedIgnored = $this->repository->find($ignoredRaw->getId());
        self::assertNotNull($reloadedNotMatchable);
        self::assertNotNull($reloadedIgnored);
        self::assertSame(IndicationRawReviewStatus::NotMatchable, $reloadedNotMatchable->getReviewStatus());
        self::assertSame(IndicationRawReviewStatus::Ignored, $reloadedIgnored->getReviewStatus());
        self::assertNull($reloadedNotMatchable->getTarget());
        self::assertSame('Low value', $reloadedIgnored->getReviewComment());
    }

    public function testReopenForReviewResetsClosedItem(): void
    {
        $reviewer = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS],
        ]);
        $raw = IndicationRawFactory::createOne([
            'code' => 705,
            'name' => 'Reopen Raw',
            'reviewStatus' => IndicationRawReviewStatus::Ignored,
            'reviewedBy' => $reviewer,
            'reviewedAt' => new \DateTimeImmutable(),
        ]);

        $this->login($reviewer);
        $this->service->reopenForReview($raw, $reviewer);

        $reloaded = $this->repository->find($raw->getId());
        self::assertNotNull($reloaded);
        self::assertSame(IndicationRawReviewStatus::Unreviewed, $reloaded->getReviewStatus());
        self::assertNull($reloaded->getReviewedBy());
        self::assertNull($reloaded->getReviewedAt());
    }

    public function testReopenForReviewThrowsForOpenStatus(): void
    {
        $reviewer = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS],
        ]);
        $raw = IndicationRawFactory::createOne(['code' => 706, 'name' => 'Still Open']);

        $this->login($reviewer);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->reopenForReview($raw, $reviewer);
    }

    public function testSaveCommentRequiresReviewPermission(): void
    {
        $participant = UserFactory::createOne(['roles' => [UserRole::USER, UserRole::PARTICIPANT]]);
        $raw = IndicationRawFactory::createOne(['code' => 707, 'name' => 'Comment Raw']);

        $this->login($participant);

        $this->expectException(AccessDeniedException::class);
        $this->service->saveComment($raw, 'Nope');
    }

    public function testSaveCommentPersistsForReviewer(): void
    {
        $reviewer = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS],
        ]);
        $raw = IndicationRawFactory::createOne(['code' => 708, 'name' => 'Save Comment Raw']);

        $this->login($reviewer);
        $this->service->saveComment($raw, 'Saved note');

        $reloaded = $this->repository->find($raw->getId());
        self::assertNotNull($reloaded);
        self::assertSame('Saved note', $reloaded->getReviewComment());
    }

    public function testMatchAndApproveRequiresAdmin(): void
    {
        $reviewer = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS],
        ]);
        $normalized = IndicationNormalizedFactory::createOne(['code' => 709, 'name' => 'Admin Norm']);
        $raw = IndicationRawFactory::createOne(['code' => 709, 'name' => 'Admin Raw']);

        $this->login($reviewer);

        $this->expectException(AccessDeniedException::class);
        $this->service->matchAndApprove($raw, $normalized, $reviewer);
    }

    public function testApproveMatchRequiresNeedsReviewStatus(): void
    {
        $reviewer = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS],
        ]);
        $raw = IndicationRawFactory::createOne(['code' => 710, 'name' => 'Wrong Status Raw']);

        $this->login($reviewer);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->approveMatch($raw, $reviewer);
    }

    public function testApproveMatchRequiresProposedTarget(): void
    {
        $reviewer = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS],
        ]);
        $raw = IndicationRawFactory::createOne([
            'code' => 711,
            'name' => 'No Target Raw',
            'reviewStatus' => IndicationRawReviewStatus::NeedsReview,
        ]);

        $this->login($reviewer);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->approveMatch($raw, $reviewer);
    }

    private function login(User $user): void
    {
        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles()),
        );
    }
}
