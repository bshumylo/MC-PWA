<?php

namespace Espo\Modules\McPwa\Hooks\User;

use Espo\Core\Hook\Hook\AfterRemove;
use Espo\Core\Hook\Hook\AfterSave;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\RemoveOptions;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Removes push subscriptions of a user who is deactivated or deleted,
 * so that notifications do not keep arriving on their devices.
 *
 * @implements AfterSave<Entity>
 * @implements AfterRemove<Entity>
 */
class CleanupSubscriptions implements AfterSave, AfterRemove
{
    public static int $order = 20;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->isNew() || $entity->get('isActive') || !$entity->isAttributeChanged('isActive')) {
            return;
        }

        $this->removeSubscriptions($entity->getId());
    }

    public function afterRemove(Entity $entity, RemoveOptions $options): void
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
