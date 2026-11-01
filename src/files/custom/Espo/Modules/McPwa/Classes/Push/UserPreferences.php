<?php

namespace Espo\Modules\McPwa\Classes\Push;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Utils\Config;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Per-user push settings stored in the user Preferences: the notification types the user wants
 * and the quiet hours. A user who has not changed anything receives everything the admin enabled.
 */
class UserPreferences
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
    ) {}

    public function isAllowed(User $user, string $type, ?DateTimeImmutable $now = null): bool
    {
        $preferences = $this->entityManager->getEntityById('Preferences', $user->getId());

        if (!$preferences) {
            return true;
        }

        $types = $preferences->get('pwaPushTypes');

        if (is_array($types) && $type !== '' && !in_array($type, $types, true)) {
            return false;
        }

        if (!$preferences->get('pwaQuietHoursEnabled')) {
            return true;
        }

        $timeZone = (string) ($preferences->get('timeZone') ?: $this->config->get('timeZone') ?: 'UTC');

        return !self::isQuietTime(
            (string) $preferences->get('pwaQuietHoursStart'),
            (string) $preferences->get('pwaQuietHoursEnd'),
            $timeZone,
            $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'))
        );
    }

    /**
     * Whether the local time (in the given time zone) falls into the interval. Midnight-crossing intervals
     * (22:00-07:00) are supported; equal bounds mean no quiet time.
     */
    public static function isQuietTime(string $start, string $end, string $timeZone, DateTimeImmutable $now): bool
    {
        $from = self::toMinutes($start);
        $to = self::toMinutes($end);

        if ($from === null || $to === null || $from === $to) {
            return false;
        }

        try {
            $local = $now->setTimezone(new DateTimeZone($timeZone));
        } catch (Throwable) {
            return false;
        }

        $minutes = (int) $local->format('G') * 60 + (int) $local->format('i');

        return $from < $to
            ? $minutes >= $from && $minutes < $to
            : $minutes >= $from || $minutes < $to;
    }

    private static function toMinutes(string $time): ?int
    {
        if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $time, $m)) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }
}
