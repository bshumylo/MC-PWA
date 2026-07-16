<?php

namespace Espo\Modules\McPwa\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Language;
use Espo\Entities\User;
use Espo\Modules\McPwa\Classes\Push\ExpiredSubscriptionException;
use Espo\Modules\McPwa\Classes\Push\WebPushSender;
use Espo\ORM\EntityManager;

/**
 * Sends a test Web Push message to all subscriptions of the current user
 * and reports a per-subscription delivery result.
 *
 * POST /api/v1/McPwa/testPush
 */
class PostTestPush implements Action
{
    public function __construct(
        private Config $config,
        private EntityManager $entityManager,
        private User $user,
        private WebPushSender $sender,
        private Language $language,
    ) {}

    public function process(Request $request): Response
    {
        if ($request->getHeader('X-Requested-With') !== 'XMLHttpRequest') {
            throw new Forbidden('Missing required header.');
        }

        if (!$this->config->get('pwaEnabled') || !$this->config->get('pwaPushEnabled')) {
            throw new Forbidden('PWA push is disabled.');
        }

        if ($this->user->isPortal() || !$this->user->isRegular() && !$this->user->isAdmin()) {
            throw new Forbidden('Not allowed for this user type.');
        }

        $subscriptions = $this->entityManager
            ->getRDBRepository('PwaSubscription')
            ->where(['userId' => $this->user->getId()])
            ->find();

        $appName = $this->config->get('pwaAppName')
            ?: $this->config->get('applicationName')
            ?: 'CRM';

        $payload = json_encode([
            'title' => $appName,
            'body' => $this->language->translate('testPushBody', 'mcPwaPush'),
            'url' => '',
            'tag' => 'espo-test-' . time(),
        ], JSON_UNESCAPED_UNICODE);

        $results = [];

        foreach ($subscriptions as $subscription) {
            $item = [
                'id' => $subscription->getId(),
                'platform' => $subscription->get('platform'),
                'userAgent' => $subscription->get('userAgent'),
            ];

            try {
                $this->sender->send(
                    (string) $subscription->get('endpoint'),
                    (string) $subscription->get('publicKey'),
                    (string) $subscription->get('authKey'),
                    (string) $payload
                );

                $item['status'] = 'sent';
            } catch (ExpiredSubscriptionException) {
                $this->entityManager->removeEntity($subscription);

                $item['status'] = 'expired';
            } catch (\Throwable $e) {
                $item['status'] = 'error';
                $item['message'] = $e->getMessage();
            }

            $results[] = $item;
        }

        return ResponseComposer::json([
            'total' => count($results),
            'list' => $results,
        ]);
    }
}
