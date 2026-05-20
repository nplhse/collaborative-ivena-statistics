<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\StatisticsFilterFactory;
use App\User\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

final readonly class StatisticsFilterValueResolver implements ValueResolverInterface
{
    public function __construct(
        private StatisticsFilterFactory $statisticsFilterFactory,
        private StatisticsFilterInputFactory $statisticsFilterInputFactory,
        private Security $security,
    ) {
    }

    /**
     * @return iterable<int, StatisticsFilter>
     */
    #[\Override]
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (StatisticsFilter::class !== $argument->getType()) {
            return [];
        }

        $user = $this->security->getUser();
        $domainUser = $user instanceof User ? $user : null;

        yield $this->statisticsFilterFactory->createFromInput(
            $this->statisticsFilterInputFactory->fromQuery($request->query, $domainUser),
            $domainUser,
        );
    }
}
