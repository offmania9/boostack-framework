<?php

namespace Boostack\Models\Log;

use Boostack\Models\Log\Database\Log_Database_Writer;
use Boostack\Models\Log\File\Log_File_Writer;
use Boostack\Models\Config;
use Boostack\Models\Auth;
use Boostack\Models\Session\Session;

/**
 * Boostack: Logger.php
 * ========================================================================
 * Copyright 2014-2026 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Alessio Debernardi
 * @version 6.2
 */

class Logger
{
    /**
     * Write a log message to the specified log driver.
     *
     * @param string $message The log message to write (default is an empty string).
     * @param int $level The level of the log message (default is Log_Level::INFORMATION).
     * @param int $type The type of log driver to use (default is Log_Driver::DATABASE).
     * @throws \Exception If the log type is not found.
     */
    public static function write($message = "", $level = Log_Level::INFORMATION, $type = Log_Driver::DATABASE): void
    {
        switch ($type) {
            case Log_Driver::DATABASE:
                if (Config::get('log_on')) {
                    try {
                        Config::constraint("database_on");
                        $currentUser = self::resolveCurrentUserForLog();
                        Log_Database_Writer::getInstance($currentUser)->Log($message, $level);
                    } catch (\Exception $e) {
                        Log_File_Writer::getInstance()->log($e, $level);
                        Log_File_Writer::getInstance()->log($message, $level);
                    }
                }
                break;
            case Log_Driver::FILE:
                if (Config::get('log_on')) {
                    Log_File_Writer::getInstance()->log($message, $level);
                    self::mirrorDatabaseErrorsToDatabaseLog($message, $level);
                }
                break;
            case Log_Driver::BOTH:
                if (Config::get('log_on')) {
                    try {
                        Log_File_Writer::getInstance()->log($message, $level);
                        Config::constraint("database_on");
                        $currentUser = self::resolveCurrentUserForLog();
                        Log_Database_Writer::getInstance($currentUser)->Log($message, $level);
                    } catch (\Exception $e) {
                        Log_File_Writer::getInstance()->log($e, $level);
                        Log_File_Writer::getInstance()->log($message, $level);
                    }
                }
                break;
            default:
                throw new \Exception("Log type not found");
        }
    }

    /**
     * Resolve current user for log attribution with robust fallbacks.
     *
     * @return object|null
     */
    private static function resolveCurrentUserForLog()
    {
        try {
            $currentUser = Auth::getUserLoggedObject();
            if (
                is_object($currentUser)
                && isset($currentUser->id)
                && is_numeric($currentUser->id)
                && (int) $currentUser->id > 1
            ) {
                return $currentUser;
            }
        } catch (\Throwable) {
            // fallback below
        }

        try {
            if (Config::get("session_on")) {
                $sessionUserId = Session::getUserID();
                if (is_numeric($sessionUserId) && (int) $sessionUserId > 1) {
                    return (object) ['id' => (int) $sessionUserId];
                }
            }
        } catch (\Throwable) {
            // keep null
        }

        return null;
    }

    /**
     * Mirror SQL/PDO errors logged on FILE to DB as well, so Smartlog can see them.
     *
     * This is best-effort: failures while writing DB logs must never break request flow.
     */
    private static function mirrorDatabaseErrorsToDatabaseLog($message, $level): void
    {
        if (!self::isMirrorableDatabaseError($message, $level)) {
            return;
        }

        try {
            Config::constraint("database_on");
            $currentUser = self::resolveCurrentUserForLog();
            Log_Database_Writer::getInstance($currentUser)->Log($message, $level);
        } catch (\Throwable) {
            // Keep file log as single source if DB logging is unavailable.
        }
    }

    /**
     * @param mixed $message
     */
    private static function isMirrorableDatabaseError($message, $level): bool
    {
        if (strtolower((string)$level) !== Log_Level::ERROR) {
            return false;
        }

        if ($message instanceof \PDOException) {
            return true;
        }

        if ($message instanceof \Throwable) {
            if (self::throwableChainHasPdoException($message)) {
                return true;
            }
            return self::isSqlStateMessage($message->getMessage());
        }

        if (is_array($message)) {
            $candidate = $message['message'] ?? '';
            return is_string($candidate) && self::isSqlStateMessage($candidate);
        }

        if (is_string($message)) {
            return self::isSqlStateMessage($message);
        }

        return false;
    }

    private static function throwableChainHasPdoException(\Throwable $throwable): bool
    {
        $current = $throwable;
        while ($current !== null) {
            if ($current instanceof \PDOException) {
                return true;
            }
            $current = $current->getPrevious();
        }
        return false;
    }

    private static function isSqlStateMessage(string $message): bool
    {
        return (bool)preg_match('/SQLSTATE\\[[0-9A-Z]{5}\\]/i', $message);
    }
}
