<?php

use Espo\Core\Container;
use Espo\ORM\EntityManager;

/**
 * Called when the extension is uninstalled.
 */
class AfterUninstall
{
    public function run(Container $container)
    {
        $entityManager = $container->getByClass(EntityManager::class);

        $scheduledJob = $entityManager
            ->getRDBRepository('ScheduledJob')
            ->where(['job' => 'McPwaSendReminderPush'])
            ->findOne();

        if ($scheduledJob) {
            $entityManager->removeEntity($scheduledJob);
        }
    }
}
