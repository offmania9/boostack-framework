<?php

declare(strict_types=1);

namespace Boostack\Security;

/**
 * Removes common credentials and authentication material before persistence.
 */
final class SensitiveDataRedactor
{
    public const REDACTED = '[redacted]';
    public const BODY_NOT_LOGGED = '[body-not-logged]';
    public const FILE_PAYLOAD_NOT_LOGGED = '[file-payload-not-logged]';

    private const SENSITIVE_KEY_PARTS = [
        'password', 'passwd', 'pwd', 'token', 'secret', 'credential',
        'authorization', 'cookie', 'csrf', 'bcsrft', 'api_key', 'apikey',
        'private_key', 'client_assertion',
    ];

    private const BODY_LOGGING_DISABLED_ENDPOINTS = [
        'login', 'registrationfirststep', 'registrationbasic',
        'authenticate', 'v1',
    ];

    /** @param mixed $value @return mixed */
    public static function redact($value, int $depth = 0)
    {
        if ($depth >= 12) {
            return '[truncated-depth]';
        }
        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $key => $item) {
                $redacted[$key] = self::isSensitiveKey((string) $key)
                    ? self::REDACTED
                    : self::redact($item, $depth + 1);
            }
            return $redacted;
        }
        if ($value instanceof \Throwable) {
            return [
                'exception_class' => get_class($value),
                'exception_code' => (int) $value->getCode(),
                'message' => self::redactText($value->getMessage()),
            ];
        }
        if (is_object($value)) {
            return self::redact(get_object_vars($value), $depth + 1);
        }
        return is_string($value) ? self::redactText($value) : $value;
    }

    /** @param mixed $payload @return mixed */
    public static function redactLogPayload($payload)
    {
        return self::redact($payload);
    }

    /** @param mixed $payload @return mixed */
    public static function redactRequestPayload($payload, string $endpoint)
    {
        return self::isBodyLoggingDisabled($endpoint)
            ? self::BODY_NOT_LOGGED
            : self::redact($payload);
    }

    /** @param mixed $payload @return mixed */
    public static function redactFilePayload($payload, string $endpoint)
    {
        if (self::isBodyLoggingDisabled($endpoint)) {
            return self::BODY_NOT_LOGGED;
        }
        return is_array($payload) ? self::FILE_PAYLOAD_NOT_LOGGED : self::redact($payload);
    }

    public static function isBodyLoggingDisabled(string $endpoint): bool
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $endpoint));
        return in_array($normalized, self::BODY_LOGGING_DISABLED_ENDPOINTS, true);
    }

    public static function redactText(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            $encoded = json_encode(self::redact($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($encoded)) {
                return $encoded;
            }
        }
        $patterns = [
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=:-]+/i' => '$1 ' . self::REDACTED,
            '/-----BEGIN [^-]*(?:PRIVATE KEY|CERTIFICATE)-----.*?-----END [^-]*(?:PRIVATE KEY|CERTIFICATE)-----/si' => self::REDACTED,
            '/\b(POST|SESSTOKEN)\s*:\s*[^\s,;&]+/i' => '$1:' . self::REDACTED,
            '/((?:password|passwd|pwd|token|secret|credential|authorization|cookie|csrf|bcsrft|api[_-]?key|private[_-]?key|client[_-]?assertion)\s*[=:]\s*)[^\s,;&]+/i' => '$1' . self::REDACTED,
            '/(["\'](?:password|passwd|pwd|token|secret|credential|authorization|cookie|csrf|bcsrft|api[_-]?key|private[_-]?key|client[_-]?assertion)["\']\s*:\s*["\'])[^"\']*(["\'])/i' => '$1' . self::REDACTED . '$2',
        ];
        return (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    public static function redactUri(string $uri): string
    {
        $parts = parse_url($uri);
        if (!is_array($parts) || !isset($parts['query'])) {
            return self::redactText($uri);
        }
        parse_str((string) $parts['query'], $query);
        $query = self::redact($query);
        foreach (['code', 'state', 'signature', 'sig'] as $key) {
            if (is_array($query) && array_key_exists($key, $query)) {
                $query[$key] = self::REDACTED;
            }
        }
        $path = (string) ($parts['path'] ?? '');
        $encoded = http_build_query(is_array($query) ? $query : []);
        return $encoded === '' ? $path : $path . '?' . $encoded;
    }

    public static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9_]/i', '_', $key));
        foreach (self::SENSITIVE_KEY_PARTS as $part) {
            if (str_contains($normalized, $part)) {
                return true;
            }
        }
        return false;
    }
}
