<?php

use Espo\Core\Container;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;

/**
 * MC PWA: generates VAPID keys (if absent) and sets default parameters.
 */
class AfterInstall
{
    public function run(Container $container): void
    {
        $config = $container->getByClass(Config::class);

        $configWriter = $container
            ->getByClass(InjectableFactory::class)
            ->create(ConfigWriter::class);

        $defaults = [
            'pwaEnabled' => true,
            'pwaPushEnabled' => true,
            'pwaStatsEnabled' => false,
            'pwaSubscriptionsEnabled' => true,
            'pwaBottomBarEnabled' => false,
            'pwaBottomBarShowLabels' => true,
            'pwaBottomBarItems' => [],
            'pwaThemeColor' => '#337ab7',
            'pwaBackgroundColor' => '#ffffff',
            'pwaPushNotificationTypes' => [
                'Assign',
                'EmailReceived',
                'Message',
                'Note',
                'MentionInPost',
                'System',
            ],
        ];

        foreach ($defaults as $param => $value) {
            if ($config->get($param) === null) {
                $configWriter->set($param, $value);
            }
        }

        if (!$config->get('pwaVapidPublicKey') || !$config->get('pwaVapidPrivateKey')) {
            $keys = $this->generateVapidKeys();

            if ($keys !== null) {
                $configWriter->set('pwaVapidPublicKey', $keys['publicKey']);
                $configWriter->set('pwaVapidPrivateKey', $keys['privateKey']);
            }
        }

        $this->migrateBottomBarItems($config, $configWriter);

        $configWriter->save();

        $this->ensureScheduledJob($container);
    }


    /**
     * Converts bottom bar items of the v1.0.x format ({url, label, iconClass,
     * iconColor} objects) to the tabList-like format (scope strings and
     * {type: 'url'} objects).
     */
    private function migrateBottomBarItems(Config $config, ConfigWriter $configWriter): void
    {
        $list = $config->get('pwaBottomBarItems');

        if (!is_array($list)) {
            return;
        }

        $changed = false;
        $result = [];

        foreach ($list as $item) {
            if (is_object($item)) {
                $item = get_object_vars($item);
            }

            if (!is_array($item) || isset($item['type']) || !isset($item['url'])) {
                $result[] = $item;

                continue;
            }

            $changed = true;

            $url = (string) $item['url'];

            if (preg_match('/^#([A-Za-z][A-Za-z0-9]*)$/', $url, $m)) {
                $result[] = $m[1];

                continue;
            }

            $result[] = [
                'id' => (string) rand(1, 1000000),
                'type' => 'url',
                'text' => $item['label'] ?? $url,
                'url' => $url,
                'iconClass' => $item['iconClass'] ?? null,
                'color' => $item['iconColor'] ?? null,
                'aclScope' => null,
                'onlyAdmin' => false,
                'openInNewTab' => (bool) preg_match('~^https?://~i', $url),
            ];
        }

        if ($changed) {
            $configWriter->set('pwaBottomBarItems', $result);
        }
    }

    /**
     * Creates the every-minute reminder-push scheduled job if it is absent.
     */
    private function ensureScheduledJob(Container $container): void
    {
        $entityManager = $container->getByClass(\Espo\ORM\EntityManager::class);

        $existing = $entityManager
            ->getRDBRepository('ScheduledJob')
            ->where(['job' => 'McPwaSendReminderPush'])
            ->findOne();

        if ($existing) {
            return;
        }

        $entityManager->createEntity('ScheduledJob', [
            'name' => 'MC PWA: Reminder Push',
            'job' => 'McPwaSendReminderPush',
            'status' => 'Active',
            'scheduling' => '* * * * *',
        ]);
    }

    /**
     * @return array{publicKey: string, privateKey: string}|null
     */
    private function generateVapidKeys(): ?array
    {
        if (!function_exists('openssl_pkey_new')) {
            return null;
        }

        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($key === false) {
            return null;
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false || !isset($details['ec']['x'], $details['ec']['y'])) {
            return null;
        }

        $publicRaw = "\x04" .
            str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) .
            str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        $privatePem = '';

        if (!openssl_pkey_export($key, $privatePem)) {
            return null;
        }

        return [
            'publicKey' => rtrim(strtr(base64_encode($publicRaw), '+/', '-_'), '='),
            'privateKey' => $privatePem,
        ];
    }
}
