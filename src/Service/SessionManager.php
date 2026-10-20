<?php

namespace App\Service;

class SessionManager
{
    private string $sessionSecret;
    private string $adminKey;
    private bool $strictCookieMode;
    private AuthSessionStore $sessions;
    private const COOKIE_NAME = 'rotator_session';
    private const SESSION_TTL = 86400;
    public const STEP_UP_TTL = 300;

    public function __construct(
        string $sessionSecret,
        string $adminKey,
        AuthSessionStore $sessions,
        bool $strictCookieMode = false,
    ) {
        $this->sessionSecret = $sessionSecret;
        $this->adminKey = $adminKey;
        $this->sessions = $sessions;
        $this->strictCookieMode = $strictCookieMode;
    }

    public function login(string $key): bool
    {
        if (!hash_equals($this->adminKey, $key)) {
            return false;
        }

        $sid = $this->sessions->start(self::SESSION_TTL);
        $payload = [
            'auth' => true,
            'exp' => time() + self::SESSION_TTL,
            'sid' => $sid,
            'token' => hash_hmac('sha256', $this->adminKey, $this->sessionSecret),
            'csrf' => bin2hex(random_bytes(32)),
        ];

        $encrypted = CryptoService::encrypt(json_encode($payload), $this->sessionSecret);
        setcookie(self::COOKIE_NAME, $encrypted, $this->cookieOptions(time() + self::SESSION_TTL));
        return true;
    }

    public function isAuthenticated(): bool
    {
        $payload = $this->authenticatedPayload();
        return $payload !== null;
    }

    public function getCsrfToken(): ?string
    {
        $payload = $this->authenticatedPayload();
        if ($payload === null) {
            return null;
        }

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

    public function hasStepUp(): bool
    {
        $sid = $this->currentSid();
        return $sid !== null && $this->sessions->stepUpActive($sid);
    }

    public function stepUp(string $key): bool
    {
        if (!hash_equals($this->adminKey, $key)) {
            return false;
        }
        $sid = $this->currentSid();
        if ($sid === null) {
            return false;
        }
        $this->sessions->grantStepUp($sid, time() + self::STEP_UP_TTL);
        return true;
    }

    public function logout(): void
    {
        $payload = $this->decodeSessionPayload();
        $sid = is_array($payload) ? ($payload['sid'] ?? null) : null;
        if (is_string($sid) && $sid !== '') {
            $this->sessions->revoke($sid);
        }
        setcookie(self::COOKIE_NAME, '', $this->cookieOptions(time() - 3600));
    }

    private function authenticatedPayload(): ?array
    {
        $payload = $this->decodeSessionPayload();
        if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) {
            return null;
        }

        $expectedToken = hash_hmac('sha256', $this->adminKey, $this->sessionSecret);
        if (!isset($payload['token']) || !hash_equals($expectedToken, $payload['token'])) {
            return null;
        }

        $sid = $payload['sid'] ?? null;
        if (!is_string($sid) || !$this->sessions->valid($sid)) {
            return null;
        }

        return $payload;
    }

    private function currentSid(): ?string
    {
        $payload = $this->authenticatedPayload();
        $sid = $payload['sid'] ?? null;
        return is_string($sid) && $sid !== '' ? $sid : null;
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
            'samesite' => 'Strict',
        ];
    }
}
