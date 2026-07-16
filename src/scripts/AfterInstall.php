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

        $configWriter->save();

        $this->ensureScheduledJob($container);
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
