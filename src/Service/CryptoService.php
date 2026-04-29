<?php

declare(strict_types=1);

namespace App\Service;

class CryptoService
{
    private const CIPHER = 'aes-256-gcm';

    /**
     * Derive a proper 32-byte AES-256 key from the master key string.
     * openssl_encrypt silently truncates / zero-pads keys that are not exactly
     * 32 bytes, reducing effective entropy when the master key length is not 32.
     * Using SHA-256 always produces a proper 256-bit key regardless of input length.
     */
    private static function deriveKey(string $key): string
    {
        return hash('sha256', $key, true);
    }

    /**
     * Encrypt data using the Master Key
     */
    public static function encrypt(string $data, string $key): string
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Encryption key cannot be empty');
        }

        $derivedKey = self::deriveKey($key);
        $ivlen = openssl_cipher_iv_length(self::CIPHER);
        $iv = random_bytes($ivlen);

        $tag = '';
        $ciphertext = openssl_encrypt($data, self::CIPHER, $derivedKey, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt data using the Master Key
     */
    public static function decrypt(string $encodedData, string $key): ?string
    {
        if ($key === '') {
            return null;
        }

        $decoded = base64_decode($encodedData, true);
        if ($decoded === false) {
            return null;
        }

        $ivlen = openssl_cipher_iv_length(self::CIPHER);
        if (strlen($decoded) < ($ivlen + 16 + 1)) {
            return null;
        }

        $iv         = substr($decoded, 0, $ivlen);
        $tag        = substr($decoded, $ivlen, 16);
        $ciphertext = substr($decoded, $ivlen + 16);
        $derivedKey = self::deriveKey($key);

        $decrypted = openssl_decrypt($ciphertext, self::CIPHER, $derivedKey, OPENSSL_RAW_DATA, $iv, $tag);
        return $decrypted === false ? null : $decrypted;
    }

    /**
     * Generate a cryptographically secure random secret
     */
    public static function generateSecret(int $length = 32, string $encoding = 'hex'): string
    {
        if ($length < 1) {
            throw new \InvalidArgumentException('Secret length must be at least 1');
        }

        if ($encoding === 'base64') {
            return substr(base64_encode(random_bytes($length)), 0, $length);
        }
        
        if ($encoding === 'alphanumeric') {
            $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $charsLen = strlen($chars);
            $res = '';
            for ($i = 0; $i < $length; $i++) {
                $res .= $chars[random_int(0, $charsLen - 1)];
            }
            return $res;
        }

        // Default to hex
        return substr(bin2hex(random_bytes((int)ceil($length / 2))), 0, $length);
    }
}
