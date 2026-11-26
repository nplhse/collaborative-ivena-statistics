<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Notification;

use App\Allocation\Application\Event\HospitalAssignmentRequestDecided;
use App\Allocation\Application\Event\HospitalAssignmentRequested;
use App\Allocation\Domain\Entity\HospitalAssignmentRequest;
use App\Allocation\Domain\Enum\HospitalAssignmentRequestStatus;
use App\Allocation\Infrastructure\Repository\HospitalAssignmentRequestRepository;
use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationAction;
use App\Notification\Application\Publishing\NotificationRequest;
use App\User\Application\Contract\AdminNotificationRecipientResolverInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Notifications of hospital assignment requests (#975), published after the commit:
 *
 * - `admin.hospital_assignment_requested` to the administrators, linking to the review page;
 * - `hospital_assignment.decided` to the requester;
 * - `hospital_assignment.ownership_transferred` to the previous owner of a transferred hospital.
 *
 * The deduplication keys name the request (and the previous owner), so a retried event notifies once. The
 * notifications store names and codes only, no comment or message text.
 *
 * @psalm-suppress UnusedClass Registered as an event listener.
 */
final readonly class HospitalAssignmentNotificationSubscriber
{
    private const string ADMIN_REVIEW_ROUTE = 'app_admin_dashboard_hospital_assignment_request_detail';
    private const string OWN_REQUESTS_ROUTE = 'app_hospital_assignment_request_index';
    private const string MY_HOSPITALS_ROUTE = 'app_hospitals_index';

    public function __construct(
        private HospitalAssignmentRequestRepository $requests,
        private AdminNotificationRecipientResolverInterface $adminRecipients,
        private NotificationPublisherInterface $publisher,
    ) {
    }

    #[AsEventListener]
    public function onRequested(HospitalAssignmentRequested $event): void
    {
        $request = $this->requests->find($event->requestId);
        $recipientIds = $this->adminRecipients->recipientIds();
        if (!$request instanceof HospitalAssignmentRequest || [] === $recipientIds) {
            return;
        }

        $this->publisher->publish(new NotificationRequest(
            type: AdminHospitalAssignmentRequestedNotification::TYPE,
            recipientUserIds: $recipientIds,
            deduplicationKey: \sprintf('%s:%s', AdminHospitalAssignmentRequestedNotification::TYPE, $event->requestPublicId),
            parameters: [
                'username' => (string) $request->getRequester()->getUsername(),
                'hospitalName' => $this->hospitalName($request),
                'reason' => $request->getReason()->value,
            ],
            action: new NotificationAction(self::ADMIN_REVIEW_ROUTE, ['entityId' => $event->requestId]),
            metadata: ['reference' => \sprintf('hospital_assignment_request:%d', $event->requestId)],
        ));
    }

    #[AsEventListener]
    public function onDecided(HospitalAssignmentRequestDecided $event): void
    {
        $request = $this->requests->find($event->requestId);
        $requesterId = $request?->getRequester()->getId();
        if (!$request instanceof HospitalAssignmentRequest || null === $requesterId) {
            return;
        }

        $approved = HospitalAssignmentRequestStatus::Approved === $request->getStatus();
        $this->publisher->publish(new NotificationRequest(
            type: HospitalAssignmentDecidedNotification::TYPE,
            recipientUserIds: [$requesterId],
            deduplicationKey: \sprintf('%s:%d', HospitalAssignmentDecidedNotification::TYPE, $event->requestId),
            parameters: [
                'hospitalName' => $this->hospitalName($request),
                'decision' => $request->getStatus()->value,
                'outcome' => $approved ? (string) $request->getOutcome()?->value : 'none',
            ],
            action: new NotificationAction($approved ? self::MY_HOSPITALS_ROUTE : self::OWN_REQUESTS_ROUTE),
            metadata: ['reference' => \sprintf('hospital_assignment_request:%d', $event->requestId)],
        ));

        if (null !== $event->previousOwnerId) {
            $this->publisher->publish(new NotificationRequest(
                type: HospitalOwnershipTransferredNotification::TYPE,
                recipientUserIds: [$event->previousOwnerId],
                deduplicationKey: \sprintf('%s:%d:%d', HospitalOwnershipTransferredNotification::TYPE, $event->requestId, $event->previousOwnerId),
                parameters: [
                    'hospitalName' => $this->hospitalName($request),
                    'newOwner' => (string) $request->getRequester()->getUsername(),
                    'access' => (string) $request->getPreviousOwnerAccess()?->value,
                ],
                action: new NotificationAction(self::MY_HOSPITALS_ROUTE),
                metadata: ['reference' => \sprintf('hospital_assignment_request:%d', $event->requestId)],
            ));
        }
    }

    private function hospitalName(HospitalAssignmentRequest $request): string
    {
        return $request->getHospital()?->getName() ?? (string) $request->getRequestedHospitalName();
    }
}
