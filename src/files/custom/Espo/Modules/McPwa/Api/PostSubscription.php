<?php

namespace Espo\Modules\McPwa\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\DateTime as DateTimeUtil;
use Espo\Entities\User;
use Espo\ORM\EntityManager;

/**
 * Saves/removes a Web Push subscription for the authenticated user.
 * POST /api/v1/McPwa/subscription
 */
class PostSubscription implements Action
{
    private const MAX_SUBSCRIPTIONS_PER_USER = 20;
    private const MAX_ENDPOINT_LENGTH = 2000;

    public function __construct(
        private Config $config,
        private EntityManager $entityManager,
        private User $user,
    ) {}

    public function process(Request $request): Response
    {
        // CSRF protection for cookie-authenticated requests: a cross-origin
        // request cannot set this header without passing a CORS preflight.
        if ($request->getHeader('X-Requested-With') !== 'XMLHttpRequest') {
            throw new Forbidden('Missing required header.');
        }

        if (!$this->config->get('pwaEnabled') || !$this->config->get('pwaPushEnabled')) {
            throw new Forbidden('PWA push is disabled.');
        }

        if ($this->user->isPortal() || !$this->user->isRegular() && !$this->user->isAdmin()) {
            throw new Forbidden('Not allowed for this user type.');
        }

        $body = $request->getParsedBody();

        $action = $body->action ?? 'subscribe';

        $endpoint = $body->subscription->endpoint ?? null;

        if (
            !is_string($endpoint) ||
            strlen($endpoint) > self::MAX_ENDPOINT_LENGTH ||
            !str_starts_with($endpoint, 'https://')
        ) {
            throw new BadRequest('Invalid endpoint.');
        }

        if ($action === 'unsubscribe') {
            $this->unsubscribe($endpoint);
        } else {
            $this->subscribe($body, $endpoint);
        }

        return ResponseComposer::json(['success' => true]);
    }

    private function subscribe(object $body, string $endpoint): void
    {
        $p256dh = $body->subscription->keys->p256dh ?? null;
        $auth = $body->subscription->keys->auth ?? null;

        if (
            !is_string($p256dh) || !is_string($auth) ||
            !preg_match('/^[A-Za-z0-9_\-=]{10,255}$/', $p256dh) ||
            !preg_match('/^[A-Za-z0-9_\-=]{10,100}$/', $auth)
        ) {
            throw new BadRequest('Invalid subscription keys.');
        }

        $platform = $this->sanitize($body->platform ?? null, 60);
        $userAgent = $this->sanitize($body->userAgent ?? null, 255);

        $now = date(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT);

        $existing = $this->entityManager
            ->getRDBRepository('PwaSubscription')
            ->where(['endpoint' => $endpoint])
            ->findOne();

        if ($existing) {
            $existing->set([
                'userId' => $this->user->getId(),
                'publicKey' => $p256dh,
                'authKey' => $auth,
                'platform' => $platform,
                'userAgent' => $userAgent,
                'modifiedAt' => $now,
            ]);

            $this->entityManager->saveEntity($existing);

            return;
        }

        $count = $this->entityManager
            ->getRDBRepository('PwaSubscription')
            ->where(['userId' => $this->user->getId()])
            ->count();

        if ($count >= self::MAX_SUBSCRIPTIONS_PER_USER) {
            throw new Forbidden('Subscription limit reached.');
        }

        $this->entityManager->createEntity('PwaSubscription', [
            'name' => trim(($this->user->getUserName() ?? '') . ' / ' . ($platform ?? 'unknown')),
            'userId' => $this->user->getId(),
            'endpoint' => $endpoint,
            'publicKey' => $p256dh,
            'authKey' => $auth,
            'platform' => $platform,
            'userAgent' => $userAgent,
            'createdAt' => $now,
            'modifiedAt' => $now,
        ]);
    }

    private function unsubscribe(string $endpoint): void
    {
        $subscription = $this->entityManager
            ->getRDBRepository('PwaSubscription')
            ->where(['endpoint' => $endpoint])
            ->findOne();

        if ($subscription) {
            $this->entityManager->removeEntity($subscription);
        }
    }

    private function sanitize(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        return mb_substr(strip_tags($value), 0, $maxLength);
    }
}
