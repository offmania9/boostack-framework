<?php

declare(strict_types=1);

namespace Boostack\Security;

use Boostack\Exceptions\Exception_LoginFailed;
use Boostack\Models\Config;
use Boostack\Models\Database\Database_PDO;
use Boostack\Models\Request;

/**
 * Database-backed login throttling shared by every application instance.
 */
final class AuthenticationRateLimiter
{
    public static function isEnabled(): bool
    {
        return (bool) Config::getOrDefault('auth_rate_limit_on', false);
    }

    public static function assertAllowed(string $identity): void
    {
        if (!self::isEnabled()) {
            return;
        }
        $pdo = Database_PDO::getInstance();
        $now = time();
        $table = self::quotedTable();
        foreach (self::scopes($identity) as [$scopeType, $scopeHash, $limit]) {
            $stmt = $pdo->prepare(
                'SELECT attempts, window_started_at, blocked_until FROM ' . $table
                . ' WHERE scope_type = :scope_type AND scope_hash = :scope_hash'
            );
            $stmt->execute([':scope_type' => $scopeType, ':scope_hash' => $scopeHash]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                continue;
            }
            $insideWindow = $now - (int) $row['window_started_at'] <= self::windowSeconds();
            if ((int) $row['blocked_until'] > $now || ($insideWindow && (int) $row['attempts'] >= $limit)) {
                throw new Exception_LoginFailed('Too many login requests. Please try again later.', 429);
            }
        }
    }

    public static function recordFailure(string $identity): void
    {
        if (!self::isEnabled()) {
            return;
        }
        $pdo = Database_PDO::getInstance();
        $now = time();
        $table = self::quotedTable();
        foreach (self::scopes($identity) as [$scopeType, $scopeHash, $limit]) {
            $stmt = $pdo->prepare(
                'INSERT INTO ' . $table . '
                    (scope_type, scope_hash, attempts, window_started_at, blocked_until, updated_at)
                 VALUES (:scope_type, :scope_hash, 1, :insert_window_started_at, 0, :insert_updated_at)
                 ON DUPLICATE KEY UPDATE
                    attempts = IF(:attempt_now - window_started_at > :attempt_window, 1, attempts + 1),
                    window_started_at = IF(:window_now - window_started_at > :window_length, :new_window_started_at, window_started_at),
                    blocked_until = IF(attempts >= :attempt_limit, :block_now + :block_seconds,
                        IF(blocked_until <= :expiry_now, 0, blocked_until)),
                    updated_at = :update_now'
            );
            $stmt->execute([
                ':scope_type' => $scopeType,
                ':scope_hash' => $scopeHash,
                ':insert_window_started_at' => $now,
                ':insert_updated_at' => $now,
                ':attempt_now' => $now,
                ':attempt_window' => self::windowSeconds(),
                ':window_now' => $now,
                ':window_length' => self::windowSeconds(),
                ':new_window_started_at' => $now,
                ':attempt_limit' => $limit,
                ':block_now' => $now,
                ':block_seconds' => self::blockSeconds(),
                ':expiry_now' => $now,
                ':update_now' => $now,
            ]);
        }
    }

    public static function recordSuccess(string $identity): void
    {
        if (!self::isEnabled()) {
            return;
        }
        $scope = self::scopes($identity)[0];
        $stmt = Database_PDO::getInstance()->prepare(
            'DELETE FROM ' . self::quotedTable() . ' WHERE scope_type = :scope_type AND scope_hash = :scope_hash'
        );
        $stmt->execute([':scope_type' => $scope[0], ':scope_hash' => $scope[1]]);
    }

    /** @return array<int,array{0:string,1:string,2:int}> */
    private static function scopes(string $identity): array
    {
        return [
            ['account', hash('sha256', strtolower(trim($identity))), self::accountLimit()],
            ['network', hash('sha256', trim((string) Request::getIpAddress())), self::networkLimit()],
        ];
    }

    private static function quotedTable(): string
    {
        $table = (string) Config::getOrDefault('auth_rate_limit_table', 'boostack_auth_rate_limit');
        if (preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) {
            throw new \RuntimeException('Invalid authentication rate-limit table name.');
        }
        return '`' . $table . '`';
    }

    private static function accountLimit(): int
    {
        return max(1, (int) Config::getOrDefault('auth_rate_limit_account_attempts', 5));
    }

    private static function networkLimit(): int
    {
        return max(1, (int) Config::getOrDefault('auth_rate_limit_network_attempts', 25));
    }

    private static function windowSeconds(): int
    {
        return max(1, (int) Config::getOrDefault('auth_rate_limit_window_seconds', 300));
    }

    private static function blockSeconds(): int
    {
        return max(1, (int) Config::getOrDefault('auth_rate_limit_block_seconds', 600));
    }
}
