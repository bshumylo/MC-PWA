<?php

namespace Espo\Modules\McPwa\Classes\Push;

/**
 * Validation of Web Push endpoints: host allowlist and public-IP check.
 */
class EndpointGuard
{
    /** Host suffixes of the known browser push services. */
    public const DEFAULT_ALLOWED_HOSTS = [
        'fcm.googleapis.com',
        'push.services.mozilla.com',
        'notify.windows.com',
        'push.apple.com',
    ];

    /** Non-public ranges that PHP's FILTER_FLAG_NO_PRIV_RANGE | NO_RES_RANGE let through. */
    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    private const BLOCKED_V6 = [
        '::/128', '::1/128', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/32',
        '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /**
     * @param string[] $extraHosts Additional allowed host suffixes (admin-defined).
     */
    public static function isHostAllowed(string $host, array $extraHosts = []): bool
    {
        $host = strtolower(rtrim($host, '.'));

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        foreach (array_merge(self::DEFAULT_ALLOWED_HOSTS, $extraHosts) as $suffix) {
            $suffix = strtolower(trim((string) $suffix, ". \t"));

            if ($suffix === '') {
                continue;
            }

            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }

        return false;
    }

    public static function isPublicIp(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 16) {
            // IPv4-mapped (::ffff:a.b.c.d) and IPv4-compatible (::a.b.c.d) addresses.
            if (
                str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff") ||
                (str_starts_with($packed, str_repeat("\0", 12)) && substr($packed, 12) !== "\0\0\0\0" &&
                    substr($packed, 12) !== "\0\0\0\1")
            ) {
                return self::isPublicIp((string) inet_ntop(substr($packed, 12)));
            }

            foreach (self::BLOCKED_V6 as $cidr) {
                if (self::inCidr($packed, $cidr)) {
                    return false;
                }
            }

            return true;
        }

        foreach (self::BLOCKED_V4 as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function inCidr(string $packedIp, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);

        $packedNet = (string) inet_pton($net);

        if (strlen($packedNet) !== strlen($packedIp)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);

        if ($bytes > 0 && substr($packedIp, 0, $bytes) !== substr($packedNet, 0, $bytes)) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xff << (8 - $rest)) & 0xff;

        return (ord($packedIp[$bytes]) & $mask) === (ord($packedNet[$bytes]) & $mask);
    }
}
