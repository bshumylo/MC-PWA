<?php

/**
 * Standalone self-test for the Web Push crypto (RFC 8291 / RFC 8292).
 *
 * Run on any server with PHP 8.1+ and ext-openssl:
 *   php tests/webpush-selftest.php
 *
 * It performs a full encrypt -> decrypt round-trip against a locally generated
 * "browser" key pair and verifies the VAPID ES256 JWT signature. No network,
 * no EspoCRM required.
 */

declare(strict_types=1);

error_reporting(E_ALL);

$failures = 0;

function check(string $name, bool $ok): void
{
    global $failures;

    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;

    if (!$ok) {
        $failures++;
    }
}

function b64uEncode(string $d): string
{
    return rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
}

function b64uDecode(string $d): string
{
    return (string) base64_decode(strtr($d, '-_', '+/'));
}

function rawP256PublicToPem(string $rawPoint): string
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $rawPoint;

    return "-----BEGIN PUBLIC KEY-----\n" .
        chunk_split(base64_encode($der), 64, "\n") .
        "-----END PUBLIC KEY-----\n";
}

function ecKeyRawPublic(array $details): string
{
    return "\x04" .
        str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) .
        str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
}

// ---------------------------------------------------------------------------
// 1. Simulate a browser subscription: generate recipient (UA) keys + auth secret.
// ---------------------------------------------------------------------------

$uaKey = openssl_pkey_new([
    'curve_name' => 'prime256v1',
    'private_key_type' => OPENSSL_KEYTYPE_EC,
]);

$uaDetails = openssl_pkey_get_details($uaKey);
$uaPublicRaw = ecKeyRawPublic($uaDetails);
$authSecret = random_bytes(16);

check('UA key generation (p256dh is 65 bytes)', strlen($uaPublicRaw) === 65);

// ---------------------------------------------------------------------------
// 2. Encrypt a payload the same way WebPushSender::encrypt() does.
// ---------------------------------------------------------------------------

$payload = json_encode([
    'title' => 'Тест',
    'body' => 'Перевірка шифрування aes128gcm',
    'url' => '#Notification',
]);

$ephemeralKey = openssl_pkey_new([
    'curve_name' => 'prime256v1',
    'private_key_type' => OPENSSL_KEYTYPE_EC,
]);

$ephDetails = openssl_pkey_get_details($ephemeralKey);
$ephemeralPublic = ecKeyRawPublic($ephDetails);

$sharedSecret = openssl_pkey_derive(
    openssl_pkey_get_public(rawP256PublicToPem($uaPublicRaw)),
    $ephemeralKey
);

$sharedSecret = str_pad($sharedSecret, 32, "\0", STR_PAD_LEFT);

$info = "WebPush: info\0" . $uaPublicRaw . $ephemeralPublic;
$ikm = hash_hkdf('sha256', $sharedSecret, 32, $info, $authSecret);

$salt = random_bytes(16);

$cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
$nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

$tag = '';
$cipherText = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);

$body = $salt . pack('N', 4096) . chr(65) . $ephemeralPublic . $cipherText . $tag;

check('Encryption produced a body', strlen($body) > 86);

// ---------------------------------------------------------------------------
// 3. Decrypt as the push service / browser would (reverse of RFC 8291).
// ---------------------------------------------------------------------------

$rSalt = substr($body, 0, 16);
$rRs = unpack('N', substr($body, 16, 4))[1];
$rIdLen = ord($body[20]);
$rEphPublic = substr($body, 21, $rIdLen);
$rCipher = substr($body, 21 + $rIdLen);

check('Record size is 4096', $rRs === 4096);
check('keyid is the 65-byte ephemeral public key', $rIdLen === 65 && $rEphPublic === $ephemeralPublic);

$rShared = openssl_pkey_derive(
    openssl_pkey_get_public(rawP256PublicToPem($rEphPublic)),
    $uaKey
);

$rShared = str_pad($rShared, 32, "\0", STR_PAD_LEFT);

check('ECDH shared secret matches on both sides', hash_equals($sharedSecret, $rShared));

$rIkm = hash_hkdf('sha256', $rShared, 32, "WebPush: info\0" . $uaPublicRaw . $rEphPublic, $authSecret);
$rCek = hash_hkdf('sha256', $rIkm, 16, "Content-Encoding: aes128gcm\0", $rSalt);
$rNonce = hash_hkdf('sha256', $rIkm, 12, "Content-Encoding: nonce\0", $rSalt);

$rTag = substr($rCipher, -16);
$rCt = substr($rCipher, 0, -16);

$plain = openssl_decrypt($rCt, 'aes-128-gcm', $rCek, OPENSSL_RAW_DATA, $rNonce, $rTag);

check('Decryption succeeded', $plain !== false);
check('Padding delimiter 0x02 present', $plain !== false && substr($plain, -1) === "\x02");
check('Round-trip payload matches', $plain !== false && substr($plain, 0, -1) === $payload);

// ---------------------------------------------------------------------------
// 4. VAPID: generate keys, sign a JWT, verify the signature.
// ---------------------------------------------------------------------------

$vapidKey = openssl_pkey_new([
    'curve_name' => 'prime256v1',
    'private_key_type' => OPENSSL_KEYTYPE_EC,
]);

$vapidDetails = openssl_pkey_get_details($vapidKey);
$vapidPublicRaw = ecKeyRawPublic($vapidDetails);

openssl_pkey_export($vapidKey, $vapidPrivatePem);

$header = b64uEncode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
$claims = b64uEncode((string) json_encode([
    'aud' => 'https://fcm.googleapis.com',
    'exp' => time() + 3600,
    'sub' => 'mailto:test@example.com',
]));

$signingInput = $header . '.' . $claims;

$derSig = '';
openssl_sign($signingInput, $derSig, $vapidPrivatePem, OPENSSL_ALGO_SHA256);

// DER -> raw r||s (same logic as WebPushSender::derToRawSignature).
$offset = 2;

if ((ord($derSig[1]) & 0x80) !== 0) {
    $offset += ord($derSig[1]) & 0x7f;
}

$raw = '';

for ($i = 0; $i < 2; $i++) {
    $len = ord($derSig[$offset + 1]);
    $val = ltrim(substr($derSig, $offset + 2, $len), "\0");
    $raw .= str_pad($val, 32, "\0", STR_PAD_LEFT);
    $offset += 2 + $len;
}

check('Raw signature is 64 bytes', strlen($raw) === 64);

// raw r||s -> DER (to verify with openssl).
$encodeInt = function (string $v): string {
    $v = ltrim($v, "\0");

    if ($v === '' || (ord($v[0]) & 0x80) !== 0) {
        $v = "\0" . $v;
    }

    return "\x02" . chr(strlen($v)) . $v;
};

$derBack = $encodeInt(substr($raw, 0, 32)) . $encodeInt(substr($raw, 32));
$derBack = "\x30" . chr(strlen($derBack)) . $derBack;

$verified = openssl_verify(
    $signingInput,
    $derBack,
    rawP256PublicToPem($vapidPublicRaw),
    OPENSSL_ALGO_SHA256
);

check('ES256 JWT signature verifies', $verified === 1);
check('VAPID public key is 65 bytes (b64url ~87 chars)', strlen(b64uDecode(b64uEncode($vapidPublicRaw))) === 65);

// ---------------------------------------------------------------------------

echo PHP_EOL . ($failures === 0 ? 'ALL TESTS PASSED' : $failures . ' TEST(S) FAILED') . PHP_EOL;

exit($failures === 0 ? 0 : 1);
