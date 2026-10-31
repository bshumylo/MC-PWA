<?php

use Espo\Core\Container;
use Espo\ORM\EntityManager;

/**
 * MC PWA: removes the data collected by the extension. Runs before the files
 * are deleted, while the PwaSubscription / PwaInstallation entities are still defined.
 */
class BeforeUninstall
{
    public function run(Container $container): void
    {
        $entityManager = $container->getByClass(EntityManager::class);

        // Push subscriptions (user link, push endpoint, keys) and installation statistics.
        foreach (['PwaSubscription', 'PwaInstallation'] as $entityType) {
            $delete = $entityManager
                ->getQueryBuilder()
                ->delete()
                ->from($entityType)
                ->build();

            $entityManager->getQueryExecutor()->execute($delete);
        }

        // Pending push jobs that would fail once the classes are gone.
        $deleteJobs = $entityManager
            ->getQueryBuilder()
            ->delete()
            ->from('Job')
            ->where(['className*' => 'Espo\\\\Modules\\\\McPwa\\\\%'])
            ->build();

        $entityManager->getQueryExecutor()->execute($deleteJobs);

        $this->removeIconCache();
    }

    private function removeIconCache(): void
    {
        $dir = 'data/cache/mc-pwa';

        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($dir);
    }
}
