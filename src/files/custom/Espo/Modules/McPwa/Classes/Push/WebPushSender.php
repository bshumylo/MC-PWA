<?php

namespace Espo\Modules\McPwa\Classes\Push;

use Espo\Core\Utils\Config;
use RuntimeException;

/**
 * Web Push sender. Pure-PHP implementation of:
 *  - RFC 8291 (Message Encryption for Web Push, aes128gcm)
 *  - RFC 8292 (VAPID, ES256 JWT)
 *
 * Uses only ext-openssl (available on the target platform). No third-party dependencies,
 * which keeps the security surface minimal.
 */
class WebPushSender
{
    private const TTL = 86400;
    private const RECORD_SIZE = 4096;
    private const MAX_PAYLOAD_LENGTH = 3000;

    public function __construct(
        private Config $config,
    ) {}

    /**
     * @throws ExpiredSubscriptionException if the subscription is gone (404/410).
     * @throws RuntimeException on other errors.
     */
    public function send(
        string $endpoint,
        string $p256dhB64,
        string $authB64,
        string $payload
    ): void {

        // Strict URL shape (https, plain DNS host, port 443, no user-info, backslash or fragment).
        // The host is taken from this check, so it is the same host that curl reads from the URL.
        $host = EndpointGuard::parseHost($endpoint);

        if ($host === null) {
            throw new RuntimeException('Invalid endpoint.');
        }

        if (!EndpointGuard::isHostAllowed($host, $this->getExtraHosts())) {
            throw new RuntimeException('Endpoint host is not allowed.');
        }

        // SSRF guard: resolve the host and reject private/reserved addresses.
        // The chosen public IP is pinned for the actual connection to avoid
        // a DNS-rebinding TOCTOU between this check and the curl request.
        $ip = $this->resolveSafeIp($host);

        if (strlen($payload) > self::MAX_PAYLOAD_LENGTH) {
            $payload = mb_strcut($payload, 0, self::MAX_PAYLOAD_LENGTH);
        }

        $userPublicKey = self::base64UrlDecode($p256dhB64);
        $authSecret = self::base64UrlDecode($authB64);

        if (strlen($userPublicKey) !== 65 || strlen($authSecret) < 16) {
            throw new RuntimeException('Invalid subscription keys.');
        }

        $body = $this->encrypt($payload, $userPublicKey, $authSecret);

        $headers = [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'Content-Length: ' . strlen($body),
            'TTL: ' . self::TTL,
            'Urgency: high',
            'Authorization: ' . $this->buildVapidAuthorization($host),
        ];

        $this->post($endpoint, $host, $ip, $headers, $body);
    }

    /**
     * Resolve the endpoint host (already validated: a DNS name, never an IP literal)
     * and ensure it points only to public IP addresses. Returns the IP to pin.
     */
    private function resolveSafeIp(string $host): string
    {
        // Collect candidate IP addresses.
        $ips = [];

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            }

            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        if ($ips === []) {
            $list = @gethostbynamel($host);

            if (is_array($list)) {
                $ips = $list;
            }
        }

        if ($ips === []) {
            throw new RuntimeException('Cannot resolve endpoint host.');
        }

        $publicIp = null;

        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                // Any private/reserved answer for this host is treated as hostile.
                throw new RuntimeException('Endpoint host is not allowed.');
            }

            if ($publicIp === null) {
                $publicIp = $ip;
            }
        }

        return (string) $publicIp;
    }

    private function isPublicIp(string $ip): bool
    {
        return EndpointGuard::isPublicIp($ip);
    }

    /**
     * Admin-defined extra allowed push host suffixes (config `pwaPushExtraHosts`).
     *
     * @return string[]
     */
    private function getExtraHosts(): array
    {
        $hosts = $this->config->get('pwaPushExtraHosts');

        return is_array($hosts) ? array_values(array_filter($hosts, 'is_string')) : [];
    }

    /**
     * RFC 8291 aes128gcm encryption.
     */
    private function encrypt(string $payload, string $userPublicKey, string $authSecret): string
    {
        $ephemeralKey = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($ephemeralKey === false) {
            throw new RuntimeException('Failed to generate ephemeral key.');
        }

        $details = openssl_pkey_get_details($ephemeralKey);

        if ($details === false || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('Failed to read ephemeral key.');
        }

        $ephemeralPublic = "\x04" .
            str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) .
            str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        $userKeyPem = self::rawP256PublicToPem($userPublicKey);

        $userKey = openssl_pkey_get_public($userKeyPem);

        if ($userKey === false) {
            throw new RuntimeException('Invalid subscription public key.');
        }

        $sharedSecret = openssl_pkey_derive($userKey, $ephemeralKey);

        if ($sharedSecret === false) {
            throw new RuntimeException('ECDH derivation failed.');
        }

        $sharedSecret = str_pad($sharedSecret, 32, "\0", STR_PAD_LEFT);

        // RFC 8291: IKM derivation.
        $info = "WebPush: info\0" . $userPublicKey . $ephemeralPublic;
        $ikm = hash_hkdf('sha256', $sharedSecret, 32, $info, $authSecret);

        $salt = random_bytes(16);

        $contentEncryptionKey = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        // Single (last) record: payload + 0x02 delimiter.
        $plainText = $payload . "\x02";

        $tag = '';

        $cipherText = openssl_encrypt(
            $plainText,
            'aes-128-gcm',
            $contentEncryptionKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($cipherText === false) {
            throw new RuntimeException('Encryption failed.');
        }

        // aes128gcm header: salt(16) | rs(4) | idlen(1) | keyid(65) | records.
        return $salt .
            pack('N', self::RECORD_SIZE) .
            pack('C', strlen($ephemeralPublic)) .
            $ephemeralPublic .
            $cipherText . $tag;
    }

    /**
     * RFC 8292 VAPID Authorization header (ES256 JWT).
     */
    private function buildVapidAuthorization(string $host): string
    {
        $publicKeyB64 = (string) $this->config->get('pwaVapidPublicKey');
        $privateKeyPem = (string) $this->config->get('pwaVapidPrivateKey');

        if ($publicKeyB64 === '' || $privateKeyPem === '') {
            throw new RuntimeException('VAPID keys are not configured.');
        }

        // The audience is the origin of the push service (always https, default port).
        $audience = 'https://' . $host;

        $subject = $this->config->get('outboundEmailFromAddress');
        $siteUrl = (string) $this->config->get('siteUrl');

        $claims = [
            'aud' => $audience,
            'exp' => time() + 12 * 3600,
            'sub' => $subject
                ? 'mailto:' . $subject
                : (str_starts_with($siteUrl, 'https://') ? $siteUrl : 'mailto:admin@localhost'),
        ];

        $header = ['typ' => 'JWT', 'alg' => 'ES256'];

        $signingInput =
            self::base64UrlEncode((string) json_encode($header)) . '.' .
            self::base64UrlEncode((string) json_encode($claims));

        $derSignature = '';

        $ok = openssl_sign($signingInput, $derSignature, $privateKeyPem, OPENSSL_ALGO_SHA256);

        if (!$ok) {
            throw new RuntimeException('JWT signing failed.');
        }

        $jwt = $signingInput . '.' . self::base64UrlEncode(self::derToRawSignature($derSignature));

        return 'vapid t=' . $jwt . ', k=' . $publicKeyB64;
    }

    /**
     * @param string[] $headers
     */
    private function post(string $endpoint, string $host, string $ip, array $headers, string $body): void
    {
        // IPv6 addresses must be bracketed for both curl options below.
        $pinnedIp = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;

        $ch = curl_init($endpoint);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            // Never route through a proxy: it would ignore the pinned IP below.
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            // Push services reply with an empty or tiny body. Abort anything larger.
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk): int {
                static $received = 0;

                $received += strlen($chunk);

                return $received > 65536 ? -1 : strlen($chunk);
            },
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            // Pin the pre-validated public IP so curl cannot be redirected to
            // a private address by a DNS answer that changed after the check.
            CURLOPT_RESOLVE => [$host . ':443:' . $pinnedIp],
            // Second layer: whatever host and port curl reads from the URL, it connects only to
            // the validated public IP on port 443. TLS still verifies the certificate for the URL host.
            CURLOPT_CONNECT_TO => ['::' . $pinnedIp . ':443'],
        ]);

        $result = curl_exec($ch);

        // With a write callback curl_exec() returns true or false, never the body.

        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        if ($result === false) {
            throw new RuntimeException('Push request failed: ' . $error);
        }

        if (in_array($statusCode, [404, 410], true)) {
            throw new ExpiredSubscriptionException('Subscription expired: ' . $statusCode);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException('Push service returned status ' . $statusCode);
        }
    }

    /**
     * Wrap a raw uncompressed P-256 point into a SubjectPublicKeyInfo PEM.
     */
    private static function rawP256PublicToPem(string $rawPoint): string
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $rawPoint;

        return "-----BEGIN PUBLIC KEY-----\n" .
            chunk_split(base64_encode($der), 64, "\n") .
            "-----END PUBLIC KEY-----\n";
    }

    /**
     * Convert a DER-encoded ECDSA signature to the raw r||s (64 bytes) form.
     */
    private static function derToRawSignature(string $der): string
    {
        $offset = 2;

        if ((ord($der[1]) & 0x80) !== 0) {
            $offset += ord($der[1]) & 0x7f;
        }

        $result = '';

        for ($i = 0; $i < 2; $i++) {
            if ($der[$offset] !== "\x02") {
                throw new RuntimeException('Invalid DER signature.');
            }

            $length = ord($der[$offset + 1]);
            $value = substr($der, $offset + 2, $length);

            $value = ltrim($value, "\0");
            $result .= str_pad($value, 32, "\0", STR_PAD_LEFT);

            $offset += 2 + $length;
        }

        return $result;
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        if ($decoded === false) {
            $decoded = base64_decode(strtr($data, '-_', '+/'));
        }

        return $decoded === false ? '' : $decoded;
    }
}
