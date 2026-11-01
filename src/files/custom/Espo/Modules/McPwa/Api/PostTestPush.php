<?php

namespace Espo\Modules\McPwa\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\DateTime as DateTimeUtil;
use Espo\Core\Utils\Language;
use Espo\Entities\User;
use Espo\Modules\McPwa\Classes\Push\Dispatcher;
use Espo\Modules\McPwa\Classes\Text;
use Espo\ORM\EntityManager;

/**
 * Sends a test Web Push message to all subscriptions of the current user
 * and reports a per-subscription delivery result.
 *
 * POST /api/v1/McPwa/testPush
 */
class PostTestPush implements Action
{
    private const MIN_INTERVAL_SECONDS = 30;
    private const MAX_SENDS = 5;
    private const DEADLINE_SECONDS = 20;

    public function __construct(
        private Config $config,
        private EntityManager $entityManager,
        private User $user,
        private Dispatcher $dispatcher,
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

        $subscriptions = iterator_to_array(
            $this->entityManager
                ->getRDBRepository('PwaSubscription')
                ->where(['userId' => $this->user->getId()])
                ->limit(0, self::MAX_SENDS)
                ->find(),
            false
        );

        // Throttle: one test run per user per interval.
        foreach ($subscriptions as $subscription) {
            $last = $subscription->get('lastTestAt');

            if ($last && strtotime($last . ' UTC') > time() - self::MIN_INTERVAL_SECONDS) {
                throw new Forbidden('Please wait before sending another test push.');
            }
        }

        $nowStr = gmdate(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT);
        $startedAt = time();

        $appName = $this->config->get('pwaAppName')
            ?: $this->config->get('applicationName')
            ?: 'CRM';

        $payload = json_encode([
            'title' => $appName,
            'body' => Text::translate($this->language, 'testPushBody', 'mcPwaPush'),
            'url' => '',
            'tag' => 'espo-test-' . time(),
        ], JSON_UNESCAPED_UNICODE);

        $results = [];

        foreach ($subscriptions as $subscription) {
            $subscription->set('lastTestAt', $nowStr);
            $this->entityManager->saveEntity($subscription, ['silent' => true]);

            $item = [
                'id' => $subscription->getId(),
                'platform' => $subscription->get('platform'),
                'userAgent' => $subscription->get('userAgent'),
            ];

            if (time() - $startedAt > self::DEADLINE_SECONDS) {
                $item['status'] = 'error';
                $results[] = $item;

                continue;
            }

            $item['status'] = $this->dispatcher->deliver($subscription, (string) $payload);

            $results[] = $item;
        }

        return ResponseComposer::json([
            'total' => count($results),
            'list' => $results,
        ]);
    }
}
