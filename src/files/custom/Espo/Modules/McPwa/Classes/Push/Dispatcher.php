<?php

namespace Espo\Modules\McPwa\Classes\Push;

use Espo\Core\Utils\DateTime as DateTimeUtil;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Delivers one payload to one subscription and keeps the delivery state on the subscription:
 * last success, last error and a failure counter. Expired subscriptions are removed.
 */
class Dispatcher
{
    public const SENT = 'sent';
    public const EXPIRED = 'expired';
    public const ERROR = 'error';

    private const ERROR_MAX_LENGTH = 255;

    /** A successful delivery is stored at most this often, unless the subscription had failures. */
    private const SUCCESS_WRITE_INTERVAL = 3600;

    public function __construct(
        private WebPushSender $sender,
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    /**
     * @return self::SENT|self::EXPIRED|self::ERROR
     */
    public function deliver(Entity $subscription, string $payload): string
    {
        try {
            $this->sender->send(
                (string) $subscription->get('endpoint'),
                (string) $subscription->get('publicKey'),
                (string) $subscription->get('authKey'),
                $payload
            );
        } catch (ExpiredSubscriptionException) {
            $this->entityManager->removeEntity($subscription);

            return self::EXPIRED;
        } catch (Throwable $e) {
            $this->log->warning(
                'McPwa: push failed for subscription ' . $subscription->getId() . ': ' . $e->getMessage()
            );

            $this->markFailure($subscription, $e->getMessage());

            return self::ERROR;
        }

        $this->markSuccess($subscription);

        return self::SENT;
    }

    private function markSuccess(Entity $subscription): void
    {
        $failureCount = (int) $subscription->get('failureCount');
        $last = $subscription->get('lastSuccessAt');

        $recent = is_string($last) &&
            strtotime($last . ' UTC') > time() - self::SUCCESS_WRITE_INTERVAL;

        if ($failureCount === 0 && $recent) {
            return;
        }

        $subscription->set([
            'lastSuccessAt' => gmdate(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT),
            'failureCount' => 0,
            'lastError' => null,
            'lastErrorAt' => null,
        ]);

        $this->save($subscription);
    }

    private function markFailure(Entity $subscription, string $message): void
    {
        $message = trim((string) preg_replace('/\s+/', ' ', $message));

        $subscription->set([
            'failureCount' => (int) $subscription->get('failureCount') + 1,
            'lastError' => mb_substr($message, 0, self::ERROR_MAX_LENGTH),
            'lastErrorAt' => gmdate(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT),
        ]);

        $this->save($subscription);
    }

    private function save(Entity $subscription): void
    {
        try {
            $this->entityManager->saveEntity($subscription, ['silent' => true, 'skipHooks' => true]);
        } catch (Throwable $e) {
            $this->log->warning('McPwa: cannot store the delivery state: ' . $e->getMessage());
        }
    }
}
