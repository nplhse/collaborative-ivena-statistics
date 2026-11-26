<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\MyRequests;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalAssignmentRequestStatus;
use App\Allocation\Infrastructure\Repository\HospitalAssignmentRequestRepository;
use App\Shared\Application\MyRequests\MyRequestEntry;
use App\Shared\Application\MyRequests\MyRequestsProviderInterface;
use App\Shared\Application\MyRequests\MyRequestsSection;
use App\User\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Hospital assignment requests (#975) on the "My requests" page (#1635). Each entry links to its card on the
 * participant's assignment request page, which keeps the withdraw action.
 *
 * @psalm-suppress UnusedClass Tagged via MyRequestsProviderInterface.
 */
final readonly class HospitalAssignmentMyRequestsProvider implements MyRequestsProviderInterface
{
    public function __construct(
        private Security $security,
        private HospitalAssignmentRequestRepository $requests,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[\Override]
    public function section(): ?MyRequestsSection
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || !$this->security->isGranted('ROLE_PARTICIPANT')) {
            return null;
        }

        $index = $this->urlGenerator->generate('app_hospital_assignment_request_index');
        $entries = [];
        foreach ($this->requests->findForRequester($user) as $request) {
            $hospital = $request->getHospital();
            $entries[] = new MyRequestEntry(
                title: $hospital instanceof Hospital ? (string) $hospital->getName() : (string) $request->getRequestedHospitalName(),
                status: new TranslatableMessage('hospital_assignment_request.status.'.$request->getStatus()->value, domain: 'allocation'),
                statusVariant: $this->variant($request->getStatus()),
                createdAt: $request->getCreatedAt(),
                url: $index.'#request-'.$request->getPublicIdString(),
            );
        }

        return new MyRequestsSection(
            key: 'hospital_assignment',
            titleKey: 'hospital_assignment_request.title.list',
            translationDomain: 'allocation',
            icon: 'tabler:building-hospital',
            weight: 10,
            entries: $entries,
            newRoute: 'app_hospital_assignment_request_new',
            newLabelKey: 'hospital_assignment_request.action.new',
        );
    }

    private function variant(HospitalAssignmentRequestStatus $status): string
    {
        return match ($status) {
            HospitalAssignmentRequestStatus::Pending => 'yellow',
            HospitalAssignmentRequestStatus::Approved => 'green',
            HospitalAssignmentRequestStatus::Rejected => 'red',
            HospitalAssignmentRequestStatus::Withdrawn => 'secondary',
        };
    }
}
