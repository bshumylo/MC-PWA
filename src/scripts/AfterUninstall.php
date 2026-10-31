<?php

use Espo\Core\Container;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\ORM\EntityManager;

/**
 * Called when the extension is uninstalled.
 */
class AfterUninstall
{
    private const CONFIG_PARAMS = [
        'pwaEnabled', 'pwaAppName', 'pwaShortName', 'pwaThemeColor', 'pwaBackgroundColor',
        'pwaIconId', 'pwaIconName', 'pwaPushEnabled', 'pwaPushNotificationTypes',
        'pwaPushExtraHosts', 'pwaStatsEnabled', 'pwaSubscriptionsEnabled',
        'pwaBottomBarEnabled', 'pwaBottomBarShowLabels', 'pwaBottomBarItems',
        'pwaVapidPublicKey', 'pwaVapidPrivateKey', 'pwaReminderPushCheckedAt',
    ];

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

        // Settings, including the VAPID key pair.
        $configWriter = $container
            ->getByClass(InjectableFactory::class)
            ->create(ConfigWriter::class);

        foreach (self::CONFIG_PARAMS as $param) {
            $configWriter->remove($param);
        }

        $configWriter->save();
    }
}
