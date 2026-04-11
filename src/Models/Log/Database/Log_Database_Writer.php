<?php

namespace Boostack\Models\Log\Database;

use Boostack\Models\Database\Database_PDO;
use Boostack\Models\Config;
use Boostack\Models\Request;

/**
 * Boostack: Log_Database_Writer.php
 * ========================================================================
 * Copyright 2014-2025 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Spagnolo Stefano <s.spagnolo@hotmail.it>
 * @version 6.0
 */

/**
 * Class Log_Database_Writer
 *
 * Responsible for writing log entries to the database.
 */
class Log_Database_Writer
{
    /** @var string|null The username associated with the log entry. */
    private $username;

    /** @var string The IP address associated with the log entry. */
    private $ip;

    /** @var string The user agent associated with the log entry. */
    private $useragent;

    /** @var string|null The referrer associated with the log entry. */
    private $referrer;

    /** @var string|null The query associated with the log entry. */
    private $query;

    /** @var string|null The framework session identifier associated with the log entry. */
    private $session_id;

    /** @var \PDO The \PDO instance for interacting with the database. */
    private $pdo;

    /** @var Log_Database_Writer|null The singleton instance of the Log_Database_Writer class. */
    private static ?\Boostack\Models\Log\Database\Log_Database_Writer $logDatabaseWriter = NULL;

    /** @var string[]|null Cached boostack_log columns. */
    private static ?array $tableColumns = null;

    /** @var bool Prevent duplicate "impress" writes on the same request. */
    private static bool $impressLogged = false;

    /** @var string|null Optional app-provided context builder class. */
    private static ?string $contextBuilderClass = null;

    /** @var bool Guard for one-time context builder resolution. */
    private static bool $contextBuilderResolved = false;

    /** @var string The table name for the log entries. */
    const TABLENAME = "boostack_log";

    /**
     * Retrieves the singleton instance of the Log_Database_Writer class.
     *
     * @param null $objUser The user associated with the log entry.
     * @return Log_Database_Writer|null The singleton instance of the Log_Database_Writer class.
     */
    static function getInstance($objUser = NULL): \Boostack\Models\Log\Database\Log_Database_Writer
    {
        if (self::$logDatabaseWriter == NULL) {
            self::$logDatabaseWriter = new Log_Database_Writer($objUser);
        } else {
            self::$logDatabaseWriter->refreshRuntimeContext($objUser);
        }
        return self::$logDatabaseWriter;
    }

    /**
     * Log_Database_Writer constructor.
     *
     * @param null $objUser The user associated with the log entry.
     */
    private function __construct($objUser = NULL)
    {
        $this->pdo = Database_PDO::getInstance();
        $this->refreshRuntimeContext($objUser);
    }

    /**
     * Logs a message with the specified level.
     *
     * @param null $message The log message.
     * @param string $level The log level.
     */
    public function Log($message = NULL, $level = "information"): void
    {
        if (!in_array($level, Config::get("log_enabledTypes"))) {
            return;
        }

        $payload = $this->normalizePayload($message, $level);
        $messageText = $payload['message'];
        $level = $payload['level'];
        $context = $this->buildContext($payload['context']);

        if ($messageText === 'impress') {
            if (self::$impressLogged) {
                return;
            }
            self::$impressLogged = true;
        }

        $querySanitized = substr(htmlspecialchars((string) $this->query, ENT_QUOTES | ENT_HTML401, 'UTF-8'), 0, 2048);

        $columns = ['id', 'datetime', 'level', 'username', 'ip', 'useragent', 'referrer', 'query', 'message'];
        $values = ['NULL', ':time', ':level', ':username', ':ip', ':useragent', ':referrer', ':query', ':message'];

        if ($this->tableHasColumn('session_id')) {
            $columns[] = 'session_id';
            $values[] = ':session_id';
        }

        if ($this->tableHasColumn('context')) {
            $columns[] = 'context';
            $values[] = ':context';
        }

        $sql = 'INSERT INTO ' . self::TABLENAME . ' (' . implode(', ', $columns) . ')
                VALUES(' . implode(', ', $values) . ')';

        $q = $this->pdo->prepare($sql);
        $q->bindValue(':time', date('Y-m-d H:i:s', time()));
        $q->bindValue(':level', $level);
        $q->bindValue(':username', $this->username);
        $q->bindValue(':ip', $this->ip);
        $q->bindValue(':useragent', $this->useragent);
        $q->bindValue(':referrer', $this->referrer);
        $q->bindValue(':query', $querySanitized);
        $q->bindValue(':message', $messageText);

        if ($this->tableHasColumn('session_id')) {
            $q->bindValue(':session_id', $this->session_id);
        }

        if ($this->tableHasColumn('context')) {
            $encodedContext = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($encodedContext)) {
                $encodedContext = '{}';
            }
            if (strlen($encodedContext) > 15000) {
                $encodedContext = substr($encodedContext, 0, 14997) . '...';
            }
            $q->bindValue(':context', $encodedContext);
        }

        $q->execute();
    }

    /**
     * @param mixed $message
     * @return array<string, mixed>
     */
    private function normalizePayload($message, string $fallbackLevel): array
    {
        $builderClass = $this->getContextBuilderClass();
        if ($builderClass !== null && is_callable([$builderClass, 'normalizePayload'])) {
            $normalized = $builderClass::normalizePayload($message, $fallbackLevel);
            if (is_array($normalized)) {
                return [
                    'message' => isset($normalized['message']) ? (string) $normalized['message'] : '',
                    'context' => isset($normalized['context']) && is_array($normalized['context']) ? $normalized['context'] : [],
                    'level' => isset($normalized['level']) ? (string) $normalized['level'] : $fallbackLevel,
                ];
            }
        }

        $messageText = '';
        $context = [];

        if ($message instanceof \Throwable) {
            $messageText = $message->getMessage();
            $context = [
                'exception_class' => get_class($message),
                'exception_code' => (int) $message->getCode(),
            ];
        } elseif (is_scalar($message)) {
            $messageText = trim((string) $message);
        } elseif (is_array($message)) {
            $messageText = trim((string) ($message['message'] ?? ''));
            $context = is_array($message['context'] ?? null) ? $message['context'] : [];
            if (!empty($message['level'])) {
                $fallbackLevel = (string) $message['level'];
            }
        } elseif (is_object($message) && method_exists($message, '__toString')) {
            $messageText = (string) $message;
        }

        if ($messageText === '') {
            $messageText = 'log-event';
        }

        return [
            'message' => $messageText,
            'context' => $context,
            'level' => strtolower($fallbackLevel),
        ];
    }

    /**
     * @param array<string, mixed> $payloadContext
     * @return array<string, mixed>
     */
    private function buildContext(array $payloadContext): array
    {
        $context = [];

        $builderClass = $this->getContextBuilderClass();
        if ($builderClass !== null && is_callable([$builderClass, 'buildRequestContext'])) {
            $requestContext = $builderClass::buildRequestContext($payloadContext);
            if (is_array($requestContext)) {
                $context = $requestContext;
            }
        }

        if (empty($context)) {
            $route = '';
            try {
                $route = Request::hasServerParam('REQUEST_URI')
                    ? (string) Request::getServerParam('REQUEST_URI')
                    : '';
            } catch (\Throwable) {
                $route = '';
            }

            $method = 'GET';
            try {
                $requestMethod = Request::getMethod();
                $method = property_exists($requestMethod, 'value')
                    ? strtoupper((string) $requestMethod->value)
                    : strtoupper((string) $requestMethod);
            } catch (\Throwable) {
                $method = 'GET';
            }

            $context = [
                'route' => $route,
                'method' => $method,
            ];
            $context = array_merge($context, $payloadContext);
        }

        if (!isset($context['session_id']) && !empty($this->session_id)) {
            $context['session_id'] = $this->session_id;
        }

        return $context;
    }

    private function resolveSessionIdentifier(): ?string
    {
        $builderClass = $this->getContextBuilderClass();
        if ($builderClass !== null && is_callable([$builderClass, 'resolveSessionIdentifier'])) {
            $sessionId = $builderClass::resolveSessionIdentifier();
            if (is_scalar($sessionId)) {
                $sessionId = trim((string) $sessionId);
                return $sessionId !== '' ? $sessionId : null;
            }
            return null;
        }

        return null;
    }

    /**
     * Refresh runtime context on each logger access.
     * This avoids stale "Anonymous" user data when login happens in the same request.
     *
     * @param mixed $objUser
     */
    private function refreshRuntimeContext($objUser = null): void
    {
        if (
            is_object($objUser)
            && isset($objUser->id)
            && is_numeric($objUser->id)
            && (int) $objUser->id > 1
        ) {
            $this->username = (string) ((int) $objUser->id);
        } else {
            $this->username = "Anonymous";
        }

        $this->ip = Request::getIpAddress();
        $this->useragent = Request::sanitizeInput(getenv('HTTP_USER_AGENT'));
        $this->referrer = Request::hasServerParam("HTTP_REFERER") ? Request::getServerParam("HTTP_REFERER") : "";
        $this->query = Request::sanitizeInput(getenv('REQUEST_URI'));
        $this->session_id = $this->resolveSessionIdentifier();
    }

    /**
     * Resolve an optional context builder class from configuration.
     * If not configured or invalid, the writer gracefully falls back to default behavior.
     */
    private function getContextBuilderClass(): ?string
    {
        if (self::$contextBuilderResolved) {
            return self::$contextBuilderClass;
        }

        self::$contextBuilderResolved = true;
        self::$contextBuilderClass = null;

        try {
            $candidate = Config::get('log_context_builder_class');
        } catch (\Throwable) {
            return null;
        }

        if (!is_string($candidate)) {
            return null;
        }

        $candidate = trim($candidate);
        if ($candidate === '' || !class_exists($candidate)) {
            return null;
        }

        self::$contextBuilderClass = $candidate;
        return self::$contextBuilderClass;
    }

    private function tableHasColumn(string $column): bool
    {
        if (self::$tableColumns === null) {
            self::$tableColumns = [];
            try {
                $result = $this->pdo->query('SHOW COLUMNS FROM ' . self::TABLENAME);
                if ($result) {
                    $rows = $result->fetchAll(\PDO::FETCH_ASSOC);
                    foreach ($rows as $row) {
                        if (!empty($row['Field'])) {
                            self::$tableColumns[] = (string) $row['Field'];
                        }
                    }
                }
            } catch (\Throwable) {
                self::$tableColumns = ['id', 'datetime', 'level', 'username', 'ip', 'useragent', 'referrer', 'query', 'message'];
            }
        }

        return in_array($column, self::$tableColumns, true);
    }
}
