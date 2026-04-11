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
 * Copyright 2014-2025 Spagnolo Stefano
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
}
