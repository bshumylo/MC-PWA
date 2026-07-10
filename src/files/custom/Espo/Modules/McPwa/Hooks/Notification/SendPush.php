<?php

namespace Espo\Modules\McPwa\Hooks\Notification;

use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Job\QueueName;
use Espo\Core\Utils\Config;
use Espo\Modules\McPwa\Jobs\SendPushNotification;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Schedules a Web Push delivery job when an in-app notification is created.
 */
class SendPush
{
    public static int $order = 20;

    public function __construct(
        private Config $config,
        private EntityManager $entityManager,
        private JobSchedulerFactory $jobSchedulerFactory,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        if (!$entity->isNew()) {
            return;
        }

        if (!$this->config->get('pwaEnabled') || !$this->config->get('pwaPushEnabled')) {
            return;
        }

        $userId = $entity->get('userId');

        if (!$userId) {
            return;
        }

        $type = (string) $entity->get('type');

        $enabledTypes = $this->config->get('pwaPushNotificationTypes');

        if (is_array($enabledTypes) && $type !== '' && !in_array($type, $enabledTypes, true)) {
            return;
        }

        $hasSubscription = (bool) $this->entityManager
            ->getRDBRepository('PwaSubscription')
            ->where(['userId' => $userId])
            ->findOne();

        if (!$hasSubscription) {
            return;
        }

        $this->jobSchedulerFactory
            ->create()
            ->setClassName(SendPushNotification::class)
            ->setQueue(QueueName::Q0)
            ->setData([
                'notificationId' => $entity->getId(),
            ])
            ->schedule();
    }
}
