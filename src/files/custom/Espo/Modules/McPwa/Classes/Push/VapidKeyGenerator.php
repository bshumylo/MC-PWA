<?php

namespace Espo\Modules\McPwa\Classes\Push;

use RuntimeException;

/**
 * Generates a VAPID key pair (P-256): the public key as an uncompressed point in base64url
 * and the private key as a PEM string.
 */
class VapidKeyGenerator
{
    /**
     * @return array{publicKey: string, privateKey: string}
     */
    public function generate(): array
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($key === false) {
            throw new RuntimeException('Failed to generate a VAPID key.');
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('Failed to read the generated VAPID key.');
        }

        $publicRaw = "\x04" .
            str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) .
            str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        $privatePem = '';

        if (!openssl_pkey_export($key, $privatePem)) {
            throw new RuntimeException('Failed to export the generated VAPID key.');
        }

        return [
            'publicKey' => WebPushSender::base64UrlEncode($publicRaw),
            'privateKey' => $privatePem,
        ];
    }
}
