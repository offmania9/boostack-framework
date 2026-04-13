<?php

namespace Boostack\Models\Session;

use Boostack\Models\Config;

/**
 * Boostack: Session.php
 * ========================================================================
 * Copyright 2014-2026 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Spagnolo Stefano <s.spagnolo@hotmail.it>
 * @version 6.2
 */

class Session
{
    private static ?\Boostack\Models\Session\Session_HTTP $sessionHTTP = null;

    /**
     * Prevents direct instantiation of Session.
     */
    private function __construct() {}

    public static function init(): \Boostack\Models\Session\Session_HTTP
    {
        if (!self::$sessionHTTP instanceof \Boostack\Models\Session\Session_HTTP) {
            self::$sessionHTTP = new Session_HTTP(Config::get('session_timeout'), Config::get('session_lifespan'));
        }
        return self::$sessionHTTP;
    }


    /**
     * Retrieves the value of a session key.
     *
     * @param string $key The key of the session.
     * @return mixed The value of the session key.
     */
    public static function get(string $key)
    {
        return self::$sessionHTTP->$key;
    }

    /**
     * Sets the value of a session key.
     *
     * @param string $key The key of the session.
     * @param mixed $value The value to set.
     */
    public static function set(string $key, $value): void
    {
        self::$sessionHTTP->$key = $value;
    }

    /**
     * Retrieves the session object.
     *
     * @return mixed The session object.
     */
    public static function getObject(): ?\Boostack\Models\Session\Session_HTTP
    {
        return self::$sessionHTTP;
    }

    /**
     * Retrieves the user object from the session.
     *
     * @return mixed The user object.
     */
    public static function getUserObject(): ?\Boostack\Models\User\User
    {

        return self::$sessionHTTP->GetUserObject();
    }

    /**
     * Method to get session impression time.
     */
    public static function getLastImpression()
    {
        return self::$sessionHTTP->getLastImpression();
    }

    /**
     * Retrieves the user ID from the session.
     *
     * @return mixed The user ID.
     */
    public static function getUserID()
    {

        return self::$sessionHTTP->GetUserID();
    }

    /**
     * Logs in a user.
     *
     * @param mixed $userID The user ID to log in.
     * @return mixed The result of the login operation.
     */
    public static function loginUser($userID)
    {

        return self::$sessionHTTP->loginUser($userID);
    }

    /**
     * Logs out the current user.
     *
     * @return mixed The result of the logout operation.
     */
    public static function logoutUser()
    {

        return self::$sessionHTTP->logoutUser();
    }

    /**
     * Checks if a user is logged in.
     *
     * @return bool True if a user is logged in, false otherwise.
     */
    public static function isLoggedIn(): bool
    {

        return self::$sessionHTTP->IsLoggedIn();
    }

    /**
     * Performs a CSRF validity check on the given POST array.
     *
     * @param array $postArray The POST array to check.
     * @param bool $throwException Whether to throw an \Exception on failure.
     * @return mixed The result of the CSRF validity check.
     */
    public static function CSRFCheckValidity(array $postArray, bool $throwException = true)
    {

        return self::$sessionHTTP->CSRFCheckValidity($postArray, $throwException);
    }

    /**
     * Renders a hidden CSRF field.
     *
     * @return mixed The rendered hidden CSRF field.
     */
    public static function CSRFRenderHiddenField(): string
    {
        return self::$sessionHTTP->CSRFRenderHiddenField();
    }
}
