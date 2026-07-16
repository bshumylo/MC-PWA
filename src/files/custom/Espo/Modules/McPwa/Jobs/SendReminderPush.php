<?php

namespace Espo\Modules\McPwa\Jobs;

use Espo\Core\InjectableFactory;
use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Core\Utils\Language;
use Espo\Core\Utils\Log;
use Espo\Modules\McPwa\Classes\Push\ExpiredSubscriptionException;
use Espo\Modules\McPwa\Classes\Push\WebPushSender;
use Espo\ORM\EntityManager;

/**
 * Scheduled job (every minute). Sends Web Push messages for popup reminders
 * that have become due since the previous run.
 *
 * Popup reminders in the CRM do not create Notification records (they are
 * delivered to open clients only), so the Notification hook never fires for
 * them. This job covers that gap: it picks up Reminder records of type Popup
 * whose remindAt falls into the (lastCheck, now] window and pushes them to
 * all subscriptions of the target user.
 *
 * Deduplication is time-window based: the upper bound of the processed
 * window is persisted in the internal config parameter
 * `pwaReminderPushCheckedAt` (only when at least one reminder was found,
 * to avoid rewriting the config file every minute).
 */
class SendReminderPush implements JobDataLess
{
    /** Max look-behind if the checkpoint is stale or absent. */
    private const MAX_WINDOW_SECONDS = 600;

    private const BODY_MAX_LENGTH = 160;

    public function __construct(
        private Config $config,
        private EntityManager $entityManager,
        private WebPushSender $sender,
        private Language $language,
        private Log $log,
        private InjectableFactory $injectableFactory,
    ) {}

    public function run(): void
    {
        if (!$this->config->get('pwaEnabled') || !$this->config->get('pwaPushEnabled')) {
            return;
        }

        $nowTs = time();
        $to = gmdate('Y-m-d H:i:s', $nowTs);

        $checkpoint = $this->config->get('pwaReminderPushCheckedAt');

        $fromTs = is_string($checkpoint) ? strtotime($checkpoint . ' UTC') : false;

        if ($fromTs === false || $fromTs < $nowTs - self::MAX_WINDOW_SECONDS) {
            $fromTs = $nowTs - self::MAX_WINDOW_SECONDS;
        }

        $from = gmdate('Y-m-d H:i:s', $fromTs);

        $reminders = $this->entityManager
            ->getRDBRepository('Reminder')
            ->where([
                'type' => 'Popup',
                'remindAt>' => $from,
                'remindAt<=' => $to,
            ])
            ->find();

        $found = false;

        $subscriptionsByUser = [];

        foreach ($reminders as $reminder) {
            $found = true;

            $userId = $reminder->get('userId');

            if (!$userId) {
                continue;
            }

            if (!array_key_exists($userId, $subscriptionsByUser)) {
                $subscriptionsByUser[$userId] = iterator_to_array(
                    $this->entityManager
                        ->getRDBRepository('PwaSubscription')
                        ->where(['userId' => $userId])
                        ->find()
                );
            }

            $subscriptions = $subscriptionsByUser[$userId];

            if ($subscriptions === []) {
                continue;
            }

            $payload = json_encode(
                $this->buildPayload($reminder, $userId),
                JSON_UNESCAPED_UNICODE
            );

            if ($payload === false) {
                continue;
            }

            foreach ($subscriptions as $subscription) {
                try {
                    $this->sender->send(
                        (string) $subscription->get('endpoint'),
                        (string) $subscription->get('publicKey'),
                        (string) $subscription->get('authKey'),
                        $payload
                    );
                } catch (ExpiredSubscriptionException) {
                    $this->entityManager->removeEntity($subscription);
                } catch (\Throwable $e) {
                    $this->log->warning(
                        'McPwa: reminder push failed for subscription ' .
                        $subscription->getId() . ': ' . $e->getMessage()
                    );
                }
            }
        }

        if ($found) {
            $configWriter = $this->injectableFactory->create(ConfigWriter::class);

            $configWriter->set('pwaReminderPushCheckedAt', $to);
            $configWriter->save();
        }
    }

    /**
     * @return array{title: string, body: string, url: string, tag: string}
     */
    private function buildPayload($reminder, string $userId): array
    {
        $entityType = (string) $reminder->get('entityType');
        $entityId = (string) $reminder->get('entityId');

        $title = $this->language->translate('reminder', 'mcPwaPush');

        $body = '';
        $url = '';

        if ($entityType !== '' && $entityId !== '') {
            $url = '#' . $entityType . '/view/' . $entityId;

            $entity = $this->entityManager->getEntityById($entityType, $entityId);

            if ($entity) {
                $name = $entity->hasAttribute('name')
                    ? trim((string) $entity->get('name'))
                    : '';

                $scopeLabel = $this->language->translate($entityType, 'scopeNames');

                $title = $this->language->translate('reminder', 'mcPwaPush') .
                    ': ' . $scopeLabel;

                $body = $name;

                $startAt = $entity->hasAttribute('dateStart')
                    ? $entity->get('dateStart')
                    : null;

                if (is_string($startAt) && $startAt !== '') {
                    $formatted = $this->formatDateForUser($startAt, $userId);

                    if ($formatted !== '') {
                        $body = trim($body . ' — ' . $formatted, ' —');
                    }
                }
            }
        }

        if (trim($body) === '') {
            $body = $this->language->translate('reminder', 'mcPwaPush');
        }

        return [
            'title' => $title,
            'body' => mb_substr($body, 0, self::BODY_MAX_LENGTH),
            'url' => $url,
            'tag' => 'espo-reminder-' . $reminder->getId(),
        ];
    }

    /**
     * Formats a UTC datetime string in the user's (or system) time zone.
     */
    private function formatDateForUser(string $utcDateTime, string $userId): string
    {
        try {
            $timeZone = null;

            $preferences = $this->entityManager->getEntityById('Preferences', $userId);

            if ($preferences) {
                $timeZone = $preferences->get('timeZone');
            }

            if (!$timeZone) {
                $timeZone = $this->config->get('timeZone') ?: 'UTC';
            }

            $dt = new \DateTimeImmutable($utcDateTime, new \DateTimeZone('UTC'));

            return $dt
                ->setTimezone(new \DateTimeZone($timeZone))
                ->format('d.m H:i');
        } catch (\Throwable) {
            return '';
        }
    }
}
