<?php

namespace Espo\Modules\McPwa\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Utils\Log;
use Espo\Modules\McPwa\Classes\Push\ExpiredSubscriptionException;
use Espo\Modules\McPwa\Classes\Push\PayloadBuilder;
use Espo\Modules\McPwa\Classes\Push\WebPushSender;
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
        private WebPushSender $sender,
        private Log $log,
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

        $subscriptions = $this->entityManager
            ->getRDBRepository('PwaSubscription')
            ->where(['userId' => $userId])
            ->find();

        $payload = json_encode(
            $this->payloadBuilder->build($notification),
            JSON_UNESCAPED_UNICODE
        );

        if ($payload === false) {
            return;
        }

        foreach ($subscriptions as $subscription) {
            try {
                $this->sender->send(
                    (string) $subscription->get('endpoint'),
                    (string) $subscription->get('publicKey'),
                    (string) $subscription->get('authKey'),
                    $payload
                );
            } catch (ExpiredSubscriptionException) {
                $this->entityManager->removeEntity($subscription);
            } catch (\Throwable $e) {
                $this->log->warning(
                    'McPwa: push send failed for subscription ' .
                    $subscription->getId() . ': ' . $e->getMessage()
                );
            }
        }
    }
}
