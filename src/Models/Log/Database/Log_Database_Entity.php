<?php
namespace Boostack\Models\Log\Database;
/**
 * Boostack: Log_Database_Entity.php
 * ========================================================================
 * Copyright 2014-2026 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Spagnolo Stefano <s.spagnolo@hotmail.it>
 * @version 6.0
 */

/**
 * Class Log_Database_Entity
 *
 * Represents a log entry stored in the database.
 */
class Log_Database_Entity extends \Boostack\Models\BaseClass
{
    /** @var string The log level. */
    protected $level;

    /** @var string The date and time of the log entry. */
    protected $datetime;

    /** @var string|null The username associated with the log entry. */
    protected $username;

    /** @var string The IP address associated with the log entry. */
    protected $ip;

    /** @var string The user agent associated with the log entry. */
    protected $useragent;

    /** @var string|null The referrer associated with the log entry. */
    protected $referrer;

    /** @var string|null The query associated with the log entry. */
    protected $query;

    /** @var string The log message. */
    protected $message;

    /** @var string The table name for the log entity. */
    const TABLENAME = "boostack_log";

    /** @var array The default values for the log entity attributes. */
    protected $default_values = [
        "id" => "",
        "level" => "information",
        "datetime" => null,
        "username" => null,
        "ip" => "",
        "useragent" => "",
        "referrer" => "",
        "query" => "",
        "message" => "",
    ];

    /**
     * Log_Database_Entity constructor.
     */
    public function __construct($id = NULL)
    {
        parent::init($id);
    }

    /**
     * Serializes the object to a JSON array.
     *
     * @return array The serialized JSON array.
     */
    public function jsonSerialize(): mixed
    {
        return ["id" => $this->id, "level" => $this->level, "datetime" => $this->datetime, "username" => $this->username, "ip" => $this->ip, "useragent" => $this->useragent, "referrer" => $this->referrer, "query" => $this->query, "message" => $this->message];
    }

    /**
     * Retrieves the attribute list for search.
     *
     * @return array The attribute list for search.
     */
    public function getAttrListForSearch(): array
    {
        return ["id" => $this->id, "level" => $this->level, "datetime" => $this->datetime, "username" => $this->username, "ip" => $this->ip, "useragent" => $this->useragent, "referrer" => $this->referrer, "query" => $this->query, "message" => $this->message];
    }
}
