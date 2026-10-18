<?php

namespace Boostack\Models;

use Boostack\Models\Session\Session;

/**
 * Boostack: Request.php
 * ========================================================================
 * Copyright 2014-2026 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Spagnolo Stefano <s.spagnolo@hotmail.it>
 * @version 6.2
 */

/**
 * Class Request
 * Represents an HTTP request handler.
 */
class Request
{
    protected static $query;
    protected static $post;
    protected static $server;
    protected static $request;
    protected static $files;
    protected static $cookie;
    protected static $headers;

    /**
     * Initializes the request by registering from global variables.
     */
    public static function init(): void
    {
        self::registerFromGlobals();
    }

    /**
     * Retrieves the current HTTP method of the request.
     *
     * @return HttpMethod The HTTP method (GET, POST, etc.).
     */
    public static function getMethod(): HttpMethod
    {
        return HttpMethod::from(self::$server['REQUEST_METHOD']);
    }

    public static function isHttps(): bool
    {
        return self::hasServerParam('HTTPS')
            && strtolower((string) self::getServerParam('HTTPS')) !== 'off'
            && (string) self::getServerParam('HTTPS') !== '0';
    }

    /**
     * Checks if a parameter exists in a specific request type.
     *
     * @param string $type The request type (e.g., 'POST', 'QUERY', 'SERVER', 'HEADERS', 'COOKIE', 'REQUEST', 'FILES').
     * @param mixed $param The parameter to check.
     */
    private static function has(string $type, string $param): bool
    {
        $params = self::${$type};

        if (strpos($param, '[') === false) {
            return isset($params[$param]);
        }

        $keys = explode('][', rtrim(ltrim($param, '['), ']'));
        $current = $params;
        foreach ($keys as $key) {
            if (!is_array($current) || !isset($current[$key])) {
                return false;
            }
            $current = $current[$key];
        }
        return true;
    }

    /**
     * Retrieves a parameter from a specific request type.
     *
     * @param string $type The request type (e.g., 'POST', 'QUERY', 'SERVER', 'HEADERS', 'COOKIE', 'REQUEST', 'FILES').
     * @param mixed $param The parameter to retrieve. Can be a simple string or an array-like string '[foo][bar]'.
     * @return mixed|null The value if found, null otherwise.
     */
    private static function get(string $type, string $param)
    {
        $params = self::${$type};
        if (strpos($param, '[') === false) {
            return isset($params[$param]) ? $params[$param] : null;
        }
        $keys = explode('][', rtrim(ltrim($param, '['), ']'));
        $current = $params;
        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }
        return $current;
    }

    /**
     * Registers request data from global variables.
     */
    private static function registerFromGlobals()
    {
        self::$query   = $_GET;
        self::$post    = $_POST;
        self::$server  = $_SERVER;
        self::$request = $_REQUEST;
        self::$files   = $_FILES;
        self::$cookie  = $_COOKIE;
        self::$headers = self::extractHeaders();
    }

    /**
     * Extracts HTTP headers from $_SERVER in a portable and case-insensitive way (compatible with Apache, Nginx, and CLI).
     *
     * @return array
     */
    private static function extractHeaders(): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        } else {
            foreach ($_SERVER as $name => $value) {
                if (str_starts_with($name, 'HTTP_')) {
                    $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                    $headers[$key] = $value;
                } elseif (in_array($name, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'])) {
                    $key = str_replace('_', '-', ucwords(strtolower($name)));
                    $headers[$key] = $value;
                }
            }
        }
        return array_change_key_case($headers, CASE_LOWER);
    }

    /**
     * Checks if a POST parameter exists.
     *
     * @param string $param The parameter name.
     * @return bool True if the parameter exists, false otherwise.
     */
    public static function hasPostParam(string $param): bool
    {
        return self::has(RequestType::POST, $param);
    }

    /**
     * Retrieves a POST parameter value.
     *
     * @param string $param The parameter name.     
     * @param mixed $default Default value if parameter is not set.
     * @return mixed|null|string The raw parameter value.
     */
    public static function getPostParam(string $param, $default = null)
    {
        $rt = RequestType::POST;
        $value = self::get($rt, $param);
        return $value !== null ? $value : $default;
    }

    /**
     * Retrieves all POST parameters.
     *
     * @return array|string The raw POST parameters.
     */
    public static function getPostArray()
    {
        return self::$post;
    }

    /**
     * Sets a POST parameter value (useful for internal overwrites).
     *
     * @param string $key The parameter key.
     * @param mixed $value The value to assign.
     * @return void
     */
    public static function setPostParam(string $key, mixed $value): void
    {
        self::$post[$key] = $value;
    }

    /**
     * Checks if a QUERY parameter exists.
     *
     * @param string $param The parameter name.
     * @return bool True if the parameter exists, false otherwise.
     */
    public static function hasQueryParam(string $param): bool
    {
        return self::has(RequestType::QUERY, $param);
    }

    /**
     * Retrieves a QUERY parameter value.
     *
     * @param string $param The parameter name.
     * @param mixed $default Default value if parameter is not set.
     * @return mixed|null|string The raw parameter value.
     */
    public static function getQueryParam(string $param, $default = null)
    {
        $rt = RequestType::QUERY;
        $value = self::get($rt, $param);
        return $value !== null ? $value : $default;
    }

    /**
     * Sets a QUERY parameter value (useful for internal overwrites).
     *
     * @param string $key The parameter key.
     * @param mixed $value The value to assign.
     * @return void
     */
    public static function setQueryParam(string $key, mixed $value): void
    {
        self::$query[$key] = $value;
    }


    /**
     * Retrieves all QUERY parameters.
     *
     * @return array|string The raw QUERY parameters.
     */
    public static function getQueryArray()
    {
        return self::$query;
    }

    /**
     * Checks if a SERVER parameter exists.
     *
     * @param string $param The parameter name.
     * @return bool True if the parameter exists, false otherwise.
     */
    public static function hasServerParam(string $param): bool
    {
        return self::has(RequestType::SERVER, $param);
    }


    /**
     * Retrieves a SERVER parameter value.
     *
     * @param string $param The parameter name.
     * @return mixed|null|string The raw parameter value.
     */
    public static function getServerParam(string $param)
    {
        $rt = RequestType::SERVER;
        return self::get($rt, $param);
    }

    /**
     * Retrieves all SERVER parameters.
     *
     * @return array|string The raw SERVER parameters.
     */
    public static function getServerArray()
    {
        return self::$server;
    }

    /**
     * Checks if a HEADER parameter exists.
     *
     * @param string $param The parameter name.
     * @return bool True if the parameter exists, false otherwise.
     */
    public static function hasHeaderParam(string $param): bool
    {
        return self::has(RequestType::HEADERS, $param);
    }

    /**
     * Retrieves a HEADER parameter value.
     *
     * @param string $param The parameter name.
     * @return mixed|null|string The raw parameter value.
     */
    public static function getHeaderParam(string $param)
    {
        $rt = RequestType::HEADERS;
        return self::get($rt, $param);
    }

    /**
     * Retrieves all HEADER parameters.
     *
     * @return array|string The raw HEADER parameters.
     */
    public static function getHeaderArray()
    {
        return self::$headers;
    }

    /**
     * Checks if a COOKIE parameter exists.
     *
     * @param string $param The parameter name.
     * @return bool True if the parameter exists, false otherwise.
     */
    public static function hasCookieParam(string $param): bool
    {
        return self::has(RequestType::COOKIE, $param);
    }

    /**
     * Retrieves a COOKIE parameter value.
     *
     * @param string $param The parameter name.
     * @return mixed|null|string The raw parameter value.
     */
    public static function getCookieParam(string $param)
    {
        $rt = RequestType::COOKIE;
        return self::get($rt, $param);
    }
    /**
     * Retrieves all COOKIE parameters.
     *
     * @return array|string The raw COOKIE parameters.
     */
    public static function getCookieArray()
    {
        return self::$cookie;
    }

    /**
     * Checks if a REQUEST parameter exists.
     *
     * @param string $param The parameter name.
     * @return bool True if the parameter exists, false otherwise.
     */
    public static function hasRequestParam(string $param): bool
    {
        return self::has(RequestType::REQUEST, $param);
    }

    /**
     * Retrieves a REQUEST parameter value.
     *
     * @param string $param The parameter name.
     * @return mixed|null|string The raw parameter value.
     */
    public static function getRequestParam(string $param)
    {
        $rt = RequestType::REQUEST;
        return self::get($rt, $param);
    }

    /**
     * Sets a REQUEST parameter value (useful for internal overwrites).
     *
     * @param string $key The parameter key.
     * @param mixed $value The value to assign.
     * @return void
     */
    public static function setRequestParam(string $key, mixed $value): void
    {
        self::$request[$key] = $value;
    }

    /**
     * Retrieves all REQUEST parameters.
     *
     * @return array|string The raw REQUEST parameters.
     */
    public static function getRequestArray()
    {
        return self::$request;
    }

    /**
     * Checks if a FILES parameter exists.
     *
     * @param string $param The parameter name.
     * @return bool True if the parameter exists, false otherwise.
     */
    public static function hasFilesParam(string $param): bool
    {
        return self::has(RequestType::FILES, $param);
    }

    /**
     * Retrieves a FILES parameter value.
     *
     * @param string $param The parameter name.
     * @return mixed|null|string The sanitized parameter value.
     */
    public static function getFilesParam(string $param)
    {
        $rt = RequestType::FILES;
        return self::sanitizeInput(self::get($rt, $param));
    }

    /**
     * Retrieves all FILES parameters.
     *
     * @return array|string The sanitized FILES parameters.
     */
    public static function getFilesArray()
    {
        return self::sanitizeInput(self::$files);
    }

    /**
     * Redirects to the maintenance page.
     */
    public static function goToMaintenance(): void
    {
        header("Location: " . Config::get("url") . Config::get("url_maintenance"));
        exit();
    }

    /**
     * Generates a friendly URL based on the virtual path provided.
     *
     * @param string $virtualPath The virtual path.
     * @return string The friendly URL.
     */
    public static function getFriendlyUrl(string $virtualPath): string
    {
        if (Config::get('session_on')) {
            $langUrl = "";
            if (Config::get('show_default_language_in_URL')) {
                $langUrl = Session::get("SESS_LANGUAGE") . "/";
            }
            return Config::get('url') . $langUrl . $virtualPath;
        }
        return Config::get('url') . $virtualPath;
    }

    /**
     * Redirects the user to the specified URL.
     *
     * @param string $URL The URL to redirect to.
     */
    public static function goToUrl(string $URL): void
    {
        header("Location: " . $URL);
        exit();
    }

    /**
     * Redirects the user to the home page.
     */
    public static function goToHome(): void
    {
        header("Location: " . Config::get("url"));
        exit();
    }

    /**
     * Redirects the user to the error page.
     *
     * @param int|null $status_code The HTTP status code to be used for the error page.
     */
    public static function goToError(?int $status_code = null): void
    {
        header("Location: " . Config::get("url") . "error/" . ($status_code === null || $status_code === 0 ? "" : $status_code));
        exit();
    }

    /**
     * Redirects the user to the logout page.
     */
    public static function goToLogout(): void
    {
        header("Location: " . Config::get("url") . "logout");
        exit();
    }


    /**
     * Retrieves the User-Agent string from the request headers.
     *
     * @return array|string The User-Agent string.
     */
    public static function getUserAgent()
    {
        return self::getServerParam("HTTP_USER_AGENT");
    }

    /**
     * Retrieves the IP address of the client.
     *
     * @return array|false|string The IP address of the client.
     */
    public static function getIpAddress()
    {
        return getenv('HTTP_CLIENT_IP') ?:
            getenv('HTTP_X_FORWARDED_FOR') ?:
            getenv('HTTP_X_FORWARDED') ?:
            getenv('HTTP_FORWARDED_FOR') ?:
            getenv('HTTP_FORWARDED') ?:
            getenv('REMOTE_ADDR');
    }

    /**
     * Escapes data for HTML output contexts.
     *
     * @param array|string $array The input data to be escaped.
     * @param string $encoding The character encoding (default is 'UTF-8').
     * @return array|string The escaped input data.
     */
    public static function sanitizeInput($data, $encoding = 'UTF-8')
    {
        if (is_array($data)) {
            return array_map(function ($value) use ($encoding) {
                return self::sanitizeInput($value, $encoding);
            }, $data);
        } elseif ($data !== null) {
            return htmlspecialchars($data, ENT_QUOTES | ENT_HTML401, $encoding);
        } else {
            return $data;
        }
    }
    /**
     * Generates a cryptographically secure token for persistent login.
     *
     * The raw token is intended for the client cookie. Applications should
     * persist only a one-way digest of it on the server.
     */
    public static function generateRememberMeToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Checks only the expected encoding and length of a remember-me token.
     *
     * Token authenticity must be established by matching its server-side
     * digest; this method does not authenticate or authorize the token.
     */
    public static function isRememberMeTokenFormatValid($cookieValue): bool
    {
        return is_string($cookieValue) && preg_match('/^[a-f0-9]{64}$/D', $cookieValue) === 1;
    }

    /**
     * @deprecated Use generateRememberMeToken().
     */
    public static function generateCookieHash(): string
    {
        return self::generateRememberMeToken();
    }

    /**
     * @deprecated Use isRememberMeTokenFormatValid().
     */
    public static function checkCookieHashValidity($cookieValue): bool
    {
        return self::isRememberMeTokenFormatValid($cookieValue);
    }

    /**
     * Checks if the time since the last request is within the accepted time limit.
     *
     * @param int|string $timeLastRequest The time of the last request.
     * @return bool Returns true if the time since the last request is within the accepted time limit, false otherwise.
     */
    public static function checkAcceptedTimeFromLastRequest(): bool
    {
        $timeLastRequest = Session::getLastImpression();
        $secondsAccepted = Config::get("seconds_accepted_between_requests");
        return !empty($timeLastRequest) && time() - $timeLastRequest >= $secondsAccepted;
    }
}
