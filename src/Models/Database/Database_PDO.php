<?php

namespace Boostack\Models\Database;

use Boostack\Models\Config;

/**
 * Boostack: Database_PDO.php
 * ========================================================================
 * Copyright 2014-2026 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Spagnolo Stefano <s.spagnolo@hotmail.it>
 * @version 6.2
 */

/**
 * Class Database_PDO
 *
 * Represents a \PDO connection to the database.
 */
class Database_PDO
{
    /** @var \PDO|null The singleton instance of \PDO. */
    private static $pdo;

    /**
     * Prevents direct instantiation of Database_PDO.
     */
    private function __construct() {}

    /**
     * Retrieves the singleton instance of \PDO.
     *
     * @param string|null $host The database host.
     * @param string|null $dbname The database name.
     * @param string|null $username The database username.
     * @param string|null $password The database password.
     * @param int $port The port number (default is 3306).
     * @return \PDO|null The \PDO instance.
     */
    public static function getInstance($host = null, $dbname = null, $username = null, $password = null, $port = 3306)
    {
        if (self::$pdo === null) {
            self::$pdo = self::createInstance($host, $dbname, $username, $password, $port);
        }
        return self::$pdo;
    }

    /**
     * Creates a new \PDO instance.
     *
     * @param string|null $host The database host.
     * @param string|null $dbname The database name.
     * @param string|null $username The database username.
     * @param string|null $password The database password.
     * @param int $port The port number (default is 3306).
     * @return \PDO The \PDO instance.
     * @throws \PDOException If connection to the database fails.
     */
    private static function createInstance($host, $dbname, $username, $password, $port)
    {
        try {
            Config::constraint("database_on");
            $charset = self::getConfigOrDefault('db_charset', 'utf8mb4');
            $collation = self::getConfigOrDefault('db_collation', 'utf8mb4_unicode_ci');

            $connection_string = Config::get("driver_pdo") . ':host=' . $host;
            $connection_string .= $port !== null ? ';port=' . $port : '';
            $connection_string .= $dbname !== null ? ';dbname=' . $dbname : '';
            $connection_string .= ';charset=' . $charset;

            $PDO = new \PDO($connection_string, $username, $password, array(
                \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . $charset . " COLLATE " . $collation,
                \PDO::ATTR_TIMEOUT => 5
            ));
            $PDO->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            return $PDO;
        } catch (\PDOException $e) {
            // Avoid recursive logging paths when DB connection itself is broken.
            $message = "[Database_PDO] DB connection failed: " . $e->getMessage();
            @error_log($message);
            $exceptionCode = is_numeric($e->getCode()) ? (int) $e->getCode() : 0;
            throw new \PDOException($e->getMessage(), $exceptionCode, $e);
        }
    }

    private static function getConfigOrDefault(string $key, string $default): string
    {
        try {
            $value = (string) Config::get($key);
            $value = trim($value);
            return $value !== '' ? $value : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
