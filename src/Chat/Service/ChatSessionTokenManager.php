<?php

declare(strict_types=1);

namespace App\Chat\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ChatSessionTokenManager
{
    public function __construct(
        #[Autowire(value: '%kernel.secret%')]
        private readonly string $secret,
        #[Autowire(value: '%env(int:CHAT_SESSION_TTL)%')]
        private readonly int $ttl,
    ) {
    }

    /**
     * Returns the token lifetime in seconds, advertised to the front as expiresIn.
     *
     * @return int
     */
    public function ttl(): int
    {
        return $this->ttl;
    }

    /**
     * Mints a fresh signed session token valid for the configured TTL.
     *
     * @return string
     */
    public function issue(): string
    {
        $now = time();
        $payload = json_encode(
            ['iat' => $now, 'exp' => $now + $this->ttl, 'nonce' => bin2hex(random_bytes(8))],
            JSON_THROW_ON_ERROR,
        );
        $body = self::base64UrlEncode($payload);

        return $body . '.' . $this->sign($body);
    }

    /**
     * Validates a token's signature and expiry.
     *
     * @param string|null $token Raw token from the X-Chat-Session header
     *
     * @return 'valid'|'expired'|'invalid'
     */
    public function check(?string $token): string
    {
        if ($token === null || substr_count($token, '.') !== 1) {
            return 'invalid';
        }

        [$body, $signature] = explode('.', $token);
        if (!hash_equals($this->sign($body), $signature)) {
            return 'invalid';
        }

        try {
            $payload = json_decode(self::base64UrlDecode($body), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'invalid';
        }

        if (!is_array($payload) || !isset($payload['exp']) || !is_int($payload['exp'])) {
            return 'invalid';
        }

        return time() >= $payload['exp'] ? 'expired' : 'valid';
    }

    /**
     * Returns the base64url-encoded HMAC-SHA256 signature of a token body.
     *
     * @param string $body Token body to sign
     *
     * @return string
     */
    private function sign(string $body): string
    {
        return self::base64UrlEncode(hash_hmac('sha256', $body, $this->secret, true));
    }

    /**
     * Base64url-encodes a raw string, without padding.
     *
     * @param string $value Raw bytes to encode
     *
     * @return string
     */
    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Decodes a base64url-encoded string.
     *
     * @param string $value Base64url string to decode
     *
     * @return string
     */
    private static function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'));
    }
}
