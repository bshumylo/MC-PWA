<?php

namespace Espo\Modules\McPwa\Hooks\User;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Removes push subscriptions of a user who is deactivated or deleted,
 * so that notifications do not keep arriving on their devices.
 */
class CleanupSubscriptions
{
    public static int $order = 20;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        if ($entity->isNew() || $entity->get('isActive') || !$entity->isAttributeChanged('isActive')) {
            return;
        }

        $this->removeSubscriptions($entity->getId());
    }

    /**
     * @param array<string, mixed> $options
     */
    public function afterRemove(Entity $entity, array $options): void
    {
        $this->removeSubscriptions($entity->getId());
    }

    private function removeSubscriptions(string $userId): void
    {
        $subscriptions = $this->entityManager
            ->getRDBRepository('PwaSubscription')
            ->where(['userId' => $userId])
            ->find();

        foreach ($subscriptions as $subscription) {
            $this->entityManager->removeEntity($subscription);
        }
    }
}
