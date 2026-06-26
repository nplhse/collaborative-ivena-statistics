<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Repository;

use App\Allocation\Domain\Enum\IndicationRawReviewStatus;
use App\Allocation\Domain\Enum\IndicationRawReviewWorklistSegment;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Repository\IndicationRawRepository;
use App\Allocation\UI\Http\DTO\IndicationRawReviewWorklistQueryDTO;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class IndicationRawReviewWorklistRepositoryTest extends KernelTestCase
{
    use Factories;

    private IndicationRawRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(IndicationRawRepository::class);
    }

    public function testGetReviewWorklistPaginatorFiltersBySegment(): void
    {
        IndicationRawFactory::createOne(['code' => 801, 'name' => 'Open Raw']);
        IndicationRawFactory::createOne([
            'code' => 802,
            'name' => 'Matched Raw',
            'reviewStatus' => IndicationRawReviewStatus::Matched,
        ]);

        $openPaginator = $this->repository->getReviewWorklistPaginator(
            new IndicationRawReviewWorklistQueryDTO(segment: IndicationRawReviewWorklistSegment::Unreviewed),
        );
        $matchedPaginator = $this->repository->getReviewWorklistPaginator(
            new IndicationRawReviewWorklistQueryDTO(segment: IndicationRawReviewWorklistSegment::Matched),
        );

        self::assertSame(1, $openPaginator->getNumResults());
        self::assertSame(1, $matchedPaginator->getNumResults());
    }

    public function testGetReviewWorklistPaginatorSearchFiltersByName(): void
    {
        IndicationRawFactory::createOne(['code' => 803, 'name' => 'Alpha indication']);
        IndicationRawFactory::createOne(['code' => 804, 'name' => 'Beta indication']);

        $paginator = $this->repository->getReviewWorklistPaginator(
            new IndicationRawReviewWorklistQueryDTO(
                search: 'alpha',
                segment: IndicationRawReviewWorklistSegment::Open,
            ),
        );

        self::assertSame(1, $paginator->getNumResults());
    }

    public function testFindNextInWorklistReturnsNextAfterCursor(): void
    {
        $first = IndicationRawFactory::createOne([
            'code' => 805,
            'name' => 'First Raw',
            'createdAt' => new \DateTimeImmutable('2026-01-01'),
        ]);
        $second = IndicationRawFactory::createOne([
            'code' => 806,
            'name' => 'Second Raw',
            'createdAt' => new \DateTimeImmutable('2026-01-02'),
        ]);

        $next = $this->repository->findNextInWorklist(
            new IndicationRawReviewWorklistQueryDTO(segment: IndicationRawReviewWorklistSegment::Unreviewed),
            $first->getId(),
        );

        self::assertNotNull($next);
        self::assertSame($second->getId(), $next->getId());
    }

    public function testGetReviewWorklistPaginatorSupportsNeedsReviewSortAndNewSegment(): void
    {
        $proposer = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS],
        ]);
        $normalized = IndicationNormalizedFactory::createOne(['code' => 807, 'name' => 'Needs Norm']);
        IndicationRawFactory::createOne([
            'code' => 807,
            'name' => 'Needs Review Raw',
            'target' => $normalized,
            'firstMatchedBy' => $proposer,
            'firstMatchedAt' => new \DateTimeImmutable('-2 days'),
            'reviewStatus' => IndicationRawReviewStatus::NeedsReview,
        ]);
        IndicationRawFactory::createOne([
            'code' => 808,
            'name' => 'Fresh Raw',
            'createdAt' => new \DateTimeImmutable('-1 day'),
        ]);

        $needsReviewPaginator = $this->repository->getReviewWorklistPaginator(
            new IndicationRawReviewWorklistQueryDTO(
                orderBy: 'desc',
                sortBy: 'firstMatchedAt',
                segment: IndicationRawReviewWorklistSegment::NeedsReview,
            ),
        );
        $newPaginator = $this->repository->getReviewWorklistPaginator(
            new IndicationRawReviewWorklistQueryDTO(segment: IndicationRawReviewWorklistSegment::New),
        );

        self::assertSame(1, $needsReviewPaginator->getNumResults());
        self::assertGreaterThanOrEqual(1, $newPaginator->getNumResults());
    }
}
