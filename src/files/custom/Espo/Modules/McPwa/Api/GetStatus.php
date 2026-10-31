<?php

namespace Espo\Modules\McPwa\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Config;
use Espo\Entities\User;
use Espo\ORM\EntityManager;

/**
 * Push health report for administrators.
 * GET /api/v1/McPwa/status
 */
class GetStatus implements Action
{
    private const RECENT_ERRORS = 10;

    public function __construct(
        private User $user,
        private Config $config,
        private EntityManager $entityManager,
    ) {}

    public function process(Request $request): Response
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden('Only administrators can read the PWA status.');
        }

        $subscriptions = $this->entityManager->getRDBRepository('PwaSubscription');

        $recent = $subscriptions
            ->where(['lastErrorAt!=' => null])
            ->order('lastErrorAt', 'DESC')
            ->limit(0, self::RECENT_ERRORS)
            ->find();

        $recentErrors = [];

        foreach ($recent as $subscription) {
            $recentErrors[] = [
                'user' => $subscription->get('userName'),
                'platform' => $subscription->get('platform'),
                'error' => $subscription->get('lastError'),
                'at' => $subscription->get('lastErrorAt'),
                'failureCount' => (int) $subscription->get('failureCount'),
            ];
        }

        return ResponseComposer::json([
            'enabled' => (bool) $this->config->get('pwaEnabled'),
            'pushEnabled' => (bool) $this->config->get('pwaPushEnabled'),
            'vapidConfigured' => $this->config->get('pwaVapidPublicKey') && $this->config->get('pwaVapidPrivateKey'),
            'vapidPublicKey' => (string) $this->config->get('pwaVapidPublicKey'),
            'subscriptions' => [
                'total' => $subscriptions->count(),
                'failing' => $subscriptions->where(['failureCount>' => 0])->count(),
            ],
            'installations' => $this->entityManager->getRDBRepository('PwaInstallation')->count(),
            'reminderCheckedAt' => $this->config->get('pwaReminderPushCheckedAt'),
            'recentErrors' => $recentErrors,
            'environment' => [
                'php' => PHP_VERSION,
                'openssl' => extension_loaded('openssl'),
                'curl' => extension_loaded('curl'),
                'gd' => extension_loaded('gd'),
            ],
        ]);
    }
}
