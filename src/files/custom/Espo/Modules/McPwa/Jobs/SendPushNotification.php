<?php

namespace Espo\Modules\McPwa\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Entities\User;
use Espo\Modules\McPwa\Classes\Push\Dispatcher;
use Espo\Modules\McPwa\Classes\Push\PayloadBuilder;
use Espo\Modules\McPwa\Classes\Push\UserPreferences;
use Espo\ORM\EntityManager;

/**
 * Sends a Web Push message for a given Notification record
 * to all subscriptions of the target user.
 */
class SendPushNotification implements Job
{
    public function __construct(
        private EntityManager $entityManager,
        private PayloadBuilder $payloadBuilder,
        private Dispatcher $dispatcher,
        private UserPreferences $userPreferences,
    ) {}

    public function run(Data $data): void
    {
        $notificationId = $data->get('notificationId');

        if (!$notificationId) {
            return;
        }

        $notification = $this->entityManager
            ->getEntityById('Notification', $notificationId);

        if (!$notification) {
            return;
        }

        // Skip if the user has already read it.
        if ($notification->get('read')) {
            return;
        }

        $userId = $notification->get('userId');

        if (!$userId) {
            return;
        }

        $user = $this->entityManager->getEntityById('User', $userId);

        if (!$user instanceof User || !$user->isActive() || $user->isPortal()) {
            return;
        }

        if (!$this->userPreferences->isAllowed($user, (string) $notification->get('type'))) {
            return;
        }

        $subscriptions = $this->entityManager
            ->getRDBRepository('PwaSubscription')
            ->where(['userId' => $userId])
            ->find();

        $payload = json_encode(
            $this->payloadBuilder->build($notification, $user),
            JSON_UNESCAPED_UNICODE
        );

        if ($payload === false) {
            return;
        }

        foreach ($subscriptions as $subscription) {
            $this->dispatcher->deliver($subscription, $payload);
        }
    }
}
