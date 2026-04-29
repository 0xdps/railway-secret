<?php

namespace App\Service;

class SessionManager
{
    private string $sessionSecret;
    private string $adminKey;
    private bool $strictCookieMode;
    private const COOKIE_NAME = 'rotator_session';
    private const SESSION_TTL = 86400;

    public function __construct(string $sessionSecret, string $adminKey, bool $strictCookieMode = false)
    {
        $this->sessionSecret = $sessionSecret;
        $this->adminKey = $adminKey;
        $this->strictCookieMode = $strictCookieMode;
    }

    public function login(string $key): bool
    {
        if (hash_equals($this->adminKey, $key)) {
            $payload = [
                'auth' => true,
                'exp' => time() + self::SESSION_TTL,
                'token' => hash_hmac('sha256', $this->adminKey, $this->sessionSecret),
                'csrf' => bin2hex(random_bytes(32)),
            ];
            
            $encrypted = CryptoService::encrypt(json_encode($payload), $this->sessionSecret);
            $options = $this->cookieOptions(time() + self::SESSION_TTL);
            setcookie(self::COOKIE_NAME, $encrypted, $options);
            return true;
        }
        return false;
    }

    public function isAuthenticated(): bool
    {
        if (!isset($_COOKIE[self::COOKIE_NAME])) {
            return false;
        }

        $payload = $this->decodeSessionPayload();
        if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) {
            return false;
        }

        $expectedToken = hash_hmac('sha256', $this->adminKey, $this->sessionSecret);
        return isset($payload['token']) && hash_equals($expectedToken, $payload['token']);
    }

    public function getCsrfToken(): ?string
    {
        if (!$this->isAuthenticated()) {
            return null;
        }

        $payload = $this->decodeSessionPayload();
        $token = $payload['csrf'] ?? null;
        if (!is_string($token) || strlen($token) < 32) {
            return null;
        }

        return $token;
    }

    public function validateCsrfToken(?string $token): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }

        $expected = $this->getCsrfToken();
        return is_string($expected) && hash_equals($expected, $token);
    }

    public function logout(): void
    {
        setcookie(self::COOKIE_NAME, '', $this->cookieOptions(time() - 3600));
    }

    private function decodeSessionPayload(): ?array
    {
        $raw = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decrypted = CryptoService::decrypt($raw, $this->sessionSecret);
        if (!$decrypted) {
            return null;
        }

        $payload = json_decode($decrypted, true);
        return is_array($payload) ? $payload : null;
    }

    private function cookieOptions(int $expires): array
    {
        return [
            'expires'  => $expires,
            'path'     => '/',
            'httponly' => true,
            'secure'   => $this->strictCookieMode,
            // Always Strict — CSRF protection must not depend on deployment env
            'samesite' => 'Strict',
        ];
    }
}
