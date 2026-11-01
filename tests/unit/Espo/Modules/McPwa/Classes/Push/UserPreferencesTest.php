<?php

namespace tests\unit\Espo\Modules\McPwa\Classes\Push;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Modules\McPwa\Classes\Push\UserPreferences;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UserPreferencesTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, string, bool}>
     */
    public static function quietProvider(): array
    {
        // [start, end, time zone, UTC time, expected quiet]
        return [
            'inside same-day interval' => ['13:00', '15:00', 'UTC', '2026-01-10 14:00', true],
            'before same-day interval' => ['13:00', '15:00', 'UTC', '2026-01-10 12:59', false],
            'end is exclusive' => ['13:00', '15:00', 'UTC', '2026-01-10 15:00', false],
            'start is inclusive' => ['13:00', '15:00', 'UTC', '2026-01-10 13:00', true],
            'night, evening part' => ['22:00', '07:00', 'UTC', '2026-01-10 23:30', true],
            'night, morning part' => ['22:00', '07:00', 'UTC', '2026-01-10 06:59', true],
            'night, daytime' => ['22:00', '07:00', 'UTC', '2026-01-10 12:00', false],
            'night end exclusive' => ['22:00', '07:00', 'UTC', '2026-01-10 07:00', false],
            'time zone shifts the clock' => ['22:00', '07:00', 'Europe/Kyiv', '2026-01-10 20:30', true],
            'time zone, outside' => ['22:00', '07:00', 'Europe/Kyiv', '2026-01-10 10:00', false],
            'equal bounds mean never' => ['08:00', '08:00', 'UTC', '2026-01-10 08:00', false],
            'invalid time' => ['25:00', '07:00', 'UTC', '2026-01-10 03:00', false],
            'invalid zone' => ['22:00', '07:00', 'Mars/Base', '2026-01-10 23:00', false],
        ];
    }

    #[DataProvider('quietProvider')]
    public function testIsQuietTime(string $start, string $end, string $zone, string $utc, bool $expected): void
    {
        $now = new DateTimeImmutable($utc, new DateTimeZone('UTC'));

        $this->assertSame($expected, UserPreferences::isQuietTime($start, $end, $zone, $now));
    }
}
