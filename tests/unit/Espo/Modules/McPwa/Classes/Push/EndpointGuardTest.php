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

    /**
     * @return array<string, array{string, bool}>
     */
    public static function endpointProvider(): array
    {
        return [
            // Shapes of real browser push endpoints (tokens are made up).
            'fcm' => ['https://fcm.googleapis.com/fcm/send/dQw4w9WgXcQ:APA91bHPRgkF_abc-DEF123', true],
            'fcm wp' => ['https://fcm.googleapis.com/wp/dQw4w9WgXcQ:APA91bHPRgkF_abc-DEF123', true],
            'mozilla' => ['https://updates.push.services.mozilla.com/wpush/v2/gAAAAABl_abc-DEF123==', true],
            'apple' => ['https://web.push.apple.com/QGuVlEEV7w8xHBTyrvyPH_abc-DEF123', true],
            'windows' => [
                'https://wns2-par02p.notify.windows.com/w/?token=BQYAAAD%2bAbc%2fDEF123%3d%3d',
                true,
            ],
            'explicit port 443' => ['https://fcm.googleapis.com:443/fcm/send/abc', true],
            'upper case host' => ['https://FCM.GoogleAPIs.com/fcm/send/abc', true],
            'root path' => ['https://fcm.googleapis.com/', true],

            // Tricks that make URL parsers disagree about the host.
            'backslash user-info' => ['https://10.0.0.5:8443\@fcm.googleapis.com/x', false],
            'backslash loopback' => ['https://127.0.0.1:8443\@fcm.googleapis.com/x', false],
            'user-info' => ['https://a@fcm.googleapis.com/x', false],
            'user-info with password' => ['https://a:b@fcm.googleapis.com/x', false],
            'at sign in path' => ['https://fcm.googleapis.com/x@y', false],
            'at sign in query' => ['https://fcm.googleapis.com/x?a=b@10.0.0.5', false],
            'backslash in path' => ['https://fcm.googleapis.com/x\y', false],
            'backslash after host' => ['https://fcm.googleapis.com\x', false],
            'other port' => ['https://fcm.googleapis.com:8443/x', false],
            'port 80' => ['https://fcm.googleapis.com:80/x', false],
            'empty port' => ['https://fcm.googleapis.com:/x', false],
            'padded port' => ['https://fcm.googleapis.com:0443/x', false],
            'fragment' => ['https://fcm.googleapis.com/x#y', false],
            'fragment after host' => ['https://10.0.0.5#@fcm.googleapis.com/x', false],
            'query after host' => ['https://10.0.0.5?@fcm.googleapis.com/x', false],

            // Whitespace and control characters.
            'space' => ['https://fcm.googleapis.com/x y', false],
            'leading space' => [' https://fcm.googleapis.com/x', false],
            'tab' => ["https://fcm.googleapis.com/x\ty", false],
            'trailing newline' => ["https://fcm.googleapis.com/x\n", false],
            'cr lf' => ["https://fcm.googleapis.com/x\r\nHost: evil.test", false],
            'null byte' => ["https://fcm.googleapis.com/x\0", false],
            'null byte in host' => ["https://10.0.0.5\0.fcm.googleapis.com/x", false],
            'non-ascii' => ["https://fcm.googleapis.com/\u{2044}x", false],
            'non-ascii host' => ["https://fcm.googleapis\u{3002}com/x", false],

            // Wrong scheme or host form.
            'http' => ['http://fcm.googleapis.com/x', false],
            'no scheme' => ['fcm.googleapis.com/x', false],
            'scheme relative' => ['//fcm.googleapis.com/x', false],
            'single slash' => ['https:/fcm.googleapis.com/x', false],
            'triple slash' => ['https:///fcm.googleapis.com/x', false],
            'no path' => ['https://fcm.googleapis.com', false],
            'query without path' => ['https://fcm.googleapis.com?x', false],
            'ipv4 literal' => ['https://8.8.8.8/x', false],
            'ipv4 loopback' => ['https://127.0.0.1/x', false],
            'decimal ip' => ['https://2130706433/x', false],
            'hex ip' => ['https://0x7f.0.0.1/x', false],
            'ipv6 literal' => ['https://[::1]/x', false],
            'single label' => ['https://localhost/x', false],
            'trailing dot' => ['https://fcm.googleapis.com./x', false],
            'empty label' => ['https://fcm..googleapis.com/x', false],
            'leading hyphen label' => ['https://-fcm.googleapis.com/x', false],
            'underscore host' => ['https://fcm_x.googleapis.com/x', false],
            'percent in host' => ['https://fcm.googleapis.com%2f.evil.test/x', false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('endpointProvider')]
    public function testIsWellFormed(string $endpoint, bool $expected): void
    {
        $this->assertSame($expected, EndpointGuard::isWellFormed($endpoint));
    }

    public function testParseHostReturnsTheLowerCaseHost(): void
    {
        $this->assertSame('fcm.googleapis.com', EndpointGuard::parseHost('https://FCM.GoogleAPIs.com:443/fcm/send/abc'));
        $this->assertSame(
            'wns2-par02p.notify.windows.com',
            EndpointGuard::parseHost('https://wns2-par02p.notify.windows.com/w/?token=abc%2bdef')
        );
        $this->assertNull(EndpointGuard::parseHost('https://10.0.0.5:8443\@fcm.googleapis.com/x'));
    }

    /**
     * For every accepted endpoint PHP's own URL parser must see the same host and no surprises.
     */
    #[DataProvider('endpointProvider')]
    public function testAcceptedEndpointsAreUnambiguous(string $endpoint, bool $expected): void
    {
        if (!$expected) {
            $this->assertNull(EndpointGuard::parseHost($endpoint));

            return;
        }

        $parts = parse_url($endpoint);

        $this->assertIsArray($parts);
        $this->assertSame(EndpointGuard::parseHost($endpoint), strtolower($parts['host'] ?? ''));
        $this->assertSame('https', $parts['scheme'] ?? null);
        $this->assertContains($parts['port'] ?? 443, [443]);
        $this->assertArrayNotHasKey('user', $parts);
        $this->assertArrayNotHasKey('pass', $parts);
        $this->assertArrayNotHasKey('fragment', $parts);
    }

    public function testExtraHostsAreHonoured(): void
    {
        $this->assertFalse(EndpointGuard::isHostAllowed('push.example.org'));
        $this->assertTrue(EndpointGuard::isHostAllowed('push.example.org', ['example.org']));
        $this->assertFalse(EndpointGuard::isHostAllowed('notexample.org', ['example.org']));
    }
}
