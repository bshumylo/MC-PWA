<?php

namespace tests\unit\Espo\Modules\McPwa\Classes\Push;

use Espo\Modules\McPwa\Classes\Push\EndpointGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EndpointGuardTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function ipProvider(): array
    {
        return [
            'public v4' => ['8.8.8.8', true],
            'public v6' => ['2607:f8b0:4005::1', true],
            'mapped public' => ['::ffff:8.8.8.8', true],
            'loopback' => ['127.0.0.1', false],
            'private 10' => ['10.1.1.1', false],
            'private 172.16' => ['172.16.5.5', false],
            'private 192.168' => ['192.168.1.1', false],
            'link-local metadata' => ['169.254.169.254', false],
            'cgnat' => ['100.64.1.1', false],
            'alibaba metadata' => ['100.100.100.200', false],
            'benchmark' => ['198.18.0.1', false],
            'multicast' => ['224.0.0.1', false],
            'mapped loopback' => ['::ffff:127.0.0.1', false],
            'mapped metadata' => ['::ffff:169.254.169.254', false],
            'v6 loopback' => ['::1', false],
            'v6 unspecified' => ['::', false],
            'nat64' => ['64:ff9b::7f00:1', false],
            '6to4' => ['2002:7f00:1::', false],
            'unique local' => ['fd00::1', false],
            'v6 link-local' => ['fe80::1', false],
            'garbage' => ['not-an-ip', false],
        ];
    }

    #[DataProvider('ipProvider')]
    public function testIsPublicIp(string $ip, bool $expected): void
    {
        $this->assertSame($expected, EndpointGuard::isPublicIp($ip));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function hostProvider(): array
    {
        return [
            'fcm' => ['fcm.googleapis.com', true],
            'mozilla' => ['updates.push.services.mozilla.com', true],
            'windows' => ['wns2-par02p.notify.windows.com', true],
            'apple' => ['web.push.apple.com', true],
            'trailing dot' => ['fcm.googleapis.com.', true],
            'upper case' => ['FCM.GOOGLEAPIS.COM', true],
            'other host' => ['evil.com', false],
            'prefix trick' => ['evilfcm.googleapis.com', false],
            'suffix trick' => ['fcm.googleapis.com.evil.com', false],
            'ip literal' => ['8.8.8.8', false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('hostProvider')]
    public function testIsHostAllowed(string $host, bool $expected): void
    {
        $this->assertSame($expected, EndpointGuard::isHostAllowed($host));
    }

    public function testExtraHostsAreHonoured(): void
    {
        $this->assertFalse(EndpointGuard::isHostAllowed('push.example.org'));
        $this->assertTrue(EndpointGuard::isHostAllowed('push.example.org', ['example.org']));
        $this->assertFalse(EndpointGuard::isHostAllowed('notexample.org', ['example.org']));
    }
}
