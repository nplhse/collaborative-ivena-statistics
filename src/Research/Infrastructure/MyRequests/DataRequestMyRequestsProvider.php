<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\MyRequests;

use App\Research\Infrastructure\Repository\DataRequestRepository;
use App\Shared\Application\MyRequests\MyRequestEntry;
use App\Shared\Application\MyRequests\MyRequestsProviderInterface;
use App\Shared\Application\MyRequests\MyRequestsSection;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Data extract requests (#1635) on the "My requests" page.
 *
 * @psalm-suppress UnusedClass Tagged via MyRequestsProviderInterface.
 */
final readonly class DataRequestMyRequestsProvider implements MyRequestsProviderInterface
{
    public function __construct(
        private Security $security,
        private DataRequestRepository $requests,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[\Override]
    public function section(): ?MyRequestsSection
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || !$this->security->isGranted(UserRole::PARTICIPANT)) {
            return null;
        }

        $entries = [];
        foreach ($this->requests->findForRequester($user) as $request) {
            $version = $request->getCurrentVersion();
            $entries[] = new MyRequestEntry(
                title: $version->getTitle(),
                status: new TranslatableMessage($request->getStatus()->labelKey(), domain: 'research'),
                statusVariant: $request->getStatus()->badgeVariant(),
                createdAt: $request->getCreatedAt(),
                url: $this->urlGenerator->generate('app_data_requests_show', ['publicId' => $request->getPublicIdString()]),
                subtitle: $version->getInstitution(),
            );
        }

        return new MyRequestsSection(
            key: 'data_request',
            titleKey: 'data_request.title.mine',
            translationDomain: 'research',
            icon: 'tabler:clipboard-list',
            weight: 20,
            entries: $entries,
            newRoute: 'app_data_requests_new',
            newLabelKey: 'data_request.action.new',
            overviewRoute: 'app_data_requests',
            overviewLabelKey: 'data_request.action.all',
        );
    }
}
