<?php

namespace Boostack\Models;

use Boostack\Exceptions\Exception_LoginFailed;
use Boostack\Models\User\User;
use Boostack\Models\Session\Session;
use Boostack\Models\Log\Log_Driver;
use Boostack\Models\Log\Log_Level;
use Boostack\Models\Log\Logger;
use Boostack\Models\Utils\Validator;
use Boostack\Exceptions\Exception_Registration;
use Boostack\Models\User\UserPrivilege;

/**
 * Boostack: Auth.php
 * ========================================================================
 * Copyright 2014-2026 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Stefano Spagnolo
 * @version 6.2
 */

class Auth
{

    const LOCK_TIMER = -1;

    const LOCK_RECAPTCHA = -2;

    /**
     * Performs user login using username and clear text password.
     *
     * @param string $username User's username.
     * @param string $password User's password.
     * @param bool $cookieRememberMe Flag to indicate whether to set the "Remember Me" cookie.
     * @return MessageBag Object containing information about the login result.
     */
    public static function loginByUsernameAndPlainPassword($username, $password, $cookieRememberMe = false)
    {
        $messageBag = new MessageBag();
        $isLockStrategyEnabled = Config::get("lockStrategy_on");
        $isreCaptchaEnabled = Config::get("reCaptcha_on");

        try {
            if (Auth::isLoggedIn()) {
                $messageBag->message = "Already logged in.";
                return $messageBag;
            }

            // Check lock strategy
            if ($isLockStrategyEnabled && Session::get("failed_login_count") > Config::get("login_maxAttempts")) {
                if (!self::checkAcceptedTimeFromLastLogin()) {
                    throw new Exception_LoginFailed("Too many login requests. Please wait a few seconds", self::LOCK_TIMER);
                }
                Session::set("failed_login_count", 0);
            }

            // Check reCaptcha
            if ($isreCaptchaEnabled) {
                $recaptchaFormData = Request::hasPostParam("g-recaptcha-response") ? Request::getPostParam("g-recaptcha-response") : null;
                if (empty($recaptchaFormData)) {
                    throw new \Exception("Missing reCAPTCHA data", self::LOCK_RECAPTCHA);
                }
                $recaptchaResponse = self::reCaptchaVerify(Request::getPostParam("g-recaptcha-response"));
                if (!$recaptchaResponse) {
                    throw new \Exception("Invalid reCAPTCHA", self::LOCK_RECAPTCHA);
                }
            }

            Auth::setLastTryLogin();

            if (!Validator::username($username)) {
                throw new Exception_LoginFailed("Invalid username format");
            }
            if (!Validator::password($password)) {
                throw new Exception_LoginFailed("Invalid password format");
            }

            if (Config::get('csrf_on') && !Session::CSRFCheckValidity(Request::getPostArray(), false)) {
                throw new Exception_LoginFailed("Invalid CSRF Token validity");
            }

            Auth::checkAndLogin($username, $password, $cookieRememberMe, true);

            if ($isLockStrategyEnabled) {
                Session::set("failed_login_count", 0);
            }
        } catch (Exception_LoginFailed $e) {
            if (Config::get("lockStrategy_on")) {
                $failed_login_count = Session::get("failed_login_count");
                $failed_login_count = is_numeric($failed_login_count) ? (int)$failed_login_count : 0;
                Session::set("failed_login_count", $failed_login_count + 1);
            }
            Logger::write($e, Log_Level::USER);
            $messageBag->error = ($e->getMessage());
            $messageBag->code = ($e->getCode());
        } catch (\Exception $e) {
            Logger::write($e, Log_Level::USER);
            $messageBag->error = ($e->getMessage());
            $messageBag->code = ($e->getCode());
        }
        return $messageBag;
    }


    /**
     * Log in the user by userID.
     *
     * @param int $userID The user ID to log in.
     */
    public static function loginByUserID($userID): void
    {
        $userClass = Config::get("use_custom_user_class") ? Config::get("custom_user_class") : User::class;
        if (!Auth::isLoggedIn()) {
            $user = new $userClass($userID);

            // Perform login 
            self::login($user->username, "", $user->pwd);
            $user->last_access = time();
            $user->save();
        }
    }


    /*
    * Log in the user using the "remember-me cookie".
    *
    * @param string $cookieValue The value of the cookie.
    * @return bool True if the login is successful, false otherwise.
    */
    public static function loginByCookie(string $cookieValue): bool
    {
        try {
            $userClass = Config::get("use_custom_user_class") ? Config::get("custom_user_class") : User::class;

            $userCredentials = $userClass::getCredentialByCookie($cookieValue);

            if ($userCredentials !== false) {
                if (Request::checkCookieHashValidity($cookieValue)) {
                    $usernameToLogin = Config::get("userToLogin") == "email" ? $userCredentials["email"] : $userCredentials["username"];

                    $loginResult = self::login($usernameToLogin, "", $userCredentials["pwd"]);

                    // If login is successful, refresh the "remember-me" cookie
                    if ($loginResult) {
                        $userObject = Session::getUserObject();
                        $userObject->refreshRememberMeCookie();
                        return true;
                    }
                } else {
                    Logger::write("checkCookieHashValidity(" . $cookieValue . "): false - IP:" . Request::getIpAddress(), Log_Level::USER);
                }
            }
        } catch (\PDOException $e) {
            Logger::write($e, Log_Level::ERROR, Log_Driver::FILE);
        } catch (\Exception $e) {
            Logger::write($e, Log_Level::ERROR);
        }
        return false;
    }


    /**
     * Register a new user.
     *
     * @param string $username The username of the new user.
     * @param string $email The email of the new user.
     * @param string $psw1 The password of the new user.
     * @param string $psw2 The confirmation password of the new user.
     * @param string|null $CSRFToken The CSRF token for validation (optional).
     * @return bool True if registration is successful, false otherwise.
     * @throws \Exception If registration fails.
     */
    public static function registration($username, $email, $psw1, $psw2, $CSRFToken = NULL): ?User
    {
        $registrationError = "";
        try {
            if (!Validator::email($email)) {
                $registrationError = "Invalid email format";
            }

            if (User::existsByEmail($email, false) || User::existsByUsername($email, false)) {
                $registrationError = "Email already registered";
            }

            if (!Validator::password($psw1)) {
                $registrationError = "Invalid password format";
            }

            if ($psw1 !== $psw2) {
                $registrationError = "Passwords must match";
            }

            if (Config::get('csrf_on')) {
                if (empty($CSRFToken)) {
                    throw new \Exception("Attention! CSRF token is required.");
                }
                $token_key = Session::getObject()->getCSRFDefaultKey();
                if (Session::CSRFCheckValidity(array($token_key => $CSRFToken))) {
                    Session::getObject()->CSRFTokenInvalidation();
                }
            }

            if (strlen($registrationError) == 0) {
                $user = new User();
                $user->username = $username;
                $user->email = $email;
                $user->active = true;
                $user->pwd = $psw1;
                $user->save();

                Auth::loginByUserID($user->id);

                if (Config::get('csrf_on')) {
                    Session::getObject()->CSRFTokenInvalidation();
                }
                return $user;
            } else {
                Logger::write($registrationError, Log_Level::ERROR);
                throw new Exception_Registration($registrationError);
            }
        } catch (\PDOException $e) {
            Logger::write($e, Log_Level::ERROR, Log_Driver::FILE);
        } catch (\Exception $e) {
            Logger::write($e, Log_Level::ERROR);
            throw $e;
        }
        return null;
    }

    /**
     * Check if a user is logged in.
     *
     * @return mixed The user's login status.
     */
    public static function isLoggedIn(): bool
    {
        return Session::isLoggedIn();
    }

    /**
     * Log out the current user.
     *
     * @return bool True if logout is successful, false otherwise.
     */
    public static function logout(): bool
    {
        try {
            if (self::isLoggedIn()) {
                Logger::write("[Logout] uid: " . Session::getUserID(), Log_Level::USER);
                // Perform logout by clearing session data
                Session::logoutUser();

                if (Config::get("cookie_on")) {
                    $cookieName = Config::get("cookie_name");
                    $cookieExpire = Config::get("cookie_expire");
                    setcookie($cookieName, false, time() - $cookieExpire);
                    setcookie($cookieName, false, time() - $cookieExpire, "/");
                }
                return true;
            }
        } catch (\PDOException $e) {
            Logger::write($e, Log_Level::ERROR, Log_Driver::FILE);
        } catch (\Exception $e) {
            Logger::write($e, Log_Level::ERROR);
        }
        return false; // Logout failed
    }

    /**
     * Get the timestamp of the last login attempt.
     *
     * @return mixed The timestamp of the last login attempt.
     */
    public static function getLastTryLogin()
    {
        return Session::get("LastTryLogin");
    }

    /**
     * Update the timestamp of the last login attempt.
     *
     * @return mixed The timestamp of the last login attempt.
     */
    public static function setLastTryLogin(): void
    {
        Session::set("LastTryLogin", time());
    }

    /**
     * Get the user object of the logged-in user.
     *
     * @return mixed The user object of the logged-in user.
     */
    public static function getUserLoggedObject()
    {
        $ret = null;
        if (Config::get("session_on")) {
            $ret = Session::getUserObject();
        }
        return $ret;
    }

    /**
     * Check if the timer lock is enabled for login attempts.
     *
     * @return bool True if the timer lock is enabled, false otherwise.
     */
    public static function isTimerLocked(): bool
    {
        return Config::get("lockStrategy_on") && Session::get("failed_login_count") >= Config::get("login_maxAttempts") && !self::checkAcceptedTimeFromLastLogin();
    }

    /**
     * Check if a captcha needs to be shown based on login attempts.
     *
     * @return bool True if a captcha needs to be shown, false otherwise.
     */
    public static function haveToShowCaptcha(): bool
    {
        return Config::get("reCaptcha_on") && Session::get("failed_login_count") >= Config::get("login_maxAttempts");
    }

    /**
     * Check and log in a user.
     *
     * @param string $username The username of the user.
     * @param string $password The password of the user.
     * @param bool $cookieRememberMe Indicates whether to remember the user with a cookie.
     * @param bool $throwException Indicates whether to throw \Exceptions on failure.
     * @return bool True if login is successful, false otherwise.
     * @throws \Exception If login fails.
     */
    private static function checkAndLogin($username, $password, $cookieRememberMe, bool $throwException = true): bool
    {
        $userClass = Config::get("use_custom_user_class") ? Config::get("custom_user_class") : User::class;
        if (Config::get("userToLogin") == "email" && !$userClass::existsByEmail($username)) {
            Logger::write("Auth -> checkAndLogin: User doesn't exist by Email Address", Log_Level::USER);
            if ($throwException) {
                throw new Exception_LoginFailed("Username or password not valid.", 6);
            }
            return false;
        }

        if (Config::get("userToLogin") == "username" && !$userClass::existsByUsername($username)) {
            Logger::write("Auth -> checkAndLogin: User doesn't exist by Username", Log_Level::USER);
            if ($throwException) {
                throw new Exception_LoginFailed("Username or password not valid.", 6);
            }
            return false;
        }

        if (Config::get("userToLogin") == "both" && (!$userClass::existsByEmail($username, false) && !$userClass::existsByUsername($username, false))) {
            Logger::write("Auth -> tryLogin: User doesn't exist by Username and by email", Log_Level::USER);
            if ($throwException) {
                throw new Exception_LoginFailed("Username or password not valid.", 6);
            }
            return false;
        }

        self::logout();
        self::login($username, $password);

        if (!self::isLoggedIn()) {
            Logger::write("Auth -> checkAndLogin: Username or password not valid.", Log_Level::USER);
            if ($throwException) {
                throw new Exception_LoginFailed("Username or password not valid.", 5);
            }
            return false;
        }

        if ($cookieRememberMe) {
            $user = Session::getUserObject();
            $user->refreshRememberMeCookie();
        }
        //Logger::write("[Login] uid: ".Session::getUserID(),Log_Level::USER);
        return true;
    }

    /**
     * Log in a user with the provided username and password.
     *
     * @param string $strUsername The username of the user.
     * @param string $strPlainPassword The plain password of the user.
     * @param string $hashedPassword The hashed password of the user.
     * @return bool True if login is successful, false otherwise.
     */
    private static function login($strUsername, $strPlainPassword, $hashedPassword = ""): bool
    {
        $userClass = Config::get("use_custom_user_class") ? Config::get("custom_user_class") : User::class;
        try {
            switch (Config::get("userToLogin")) {
                case "email":
                    $userData = $userClass::getActiveCredentialByEmail($strUsername);
                    break;
                case "both":
                    $userData = $userClass::getActiveCredentialByEmailOrUsername($strUsername, $strUsername);
                    break;
                default:
                    $userData = $userClass::getActiveCredentialByUsername($strUsername);
                    break;
            }
            if ($userData !== false) {
                $userPwd = $userData["pwd"];
                $userId = $userData["id"];
                if (($hashedPassword == "" && password_verify($strPlainPassword, $userPwd)) ||
                    ($hashedPassword !== "" && $hashedPassword == $userPwd)
                ) {
                    Session::loginUser($userId);
                    $userObject = new $userClass($userId);
                    $userObject->last_access = time();
                    $userObject->save();
                    Logger::write("[Login] uid: " . $userId, Log_Level::USER);
                    return true;
                }
            }
        } catch (\PDOException $e) {
            Logger::write($e, Log_Level::ERROR, Log_Driver::FILE);
        } catch (\Exception $e) {
            Logger::write($e, Log_Level::ERROR);
        }
        return false;
    }

    /**
     * Verify the reCaptcha response.
     *
     * @param string $response The reCaptcha response.
     * @return bool True if the reCaptcha is valid, false otherwise.
     */
    private static function reCaptchaVerify($response): bool
    {
        $reCaptcha_private = Config::get("reCaptcha_private_serverside_key");
        $curlRequest = new \Boostack\Models\Curl\CurlRequest();
        $curlRequest->setEndpoint(Config::get("reCaptcha_verify_endpoint"));
        $curlRequest->setIsPost(true);
        $curlRequest->setReturnTransfer(true);
        $curlRequest->setPostFields([
            "secret" => $reCaptcha_private,
            "response" => $response
        ]);
        $response = $curlRequest->send();
        $a =  json_decode($response->data, true);
        return (!$response->hasError() && $a["success"]);
    }

    /**
     * Check if enough time has passed since the last login attempt.
     *
     * @param int $lastLogin The timestamp of the last login attempt.
     * @return bool True if enough time has passed, false otherwise.
     */
    private static function checkAcceptedTimeFromLastLogin(): bool
    {
        $last_login_timestamp = self::getLastTryLogin();
        time();
        return !empty($last_login_timestamp) && (time() - $last_login_timestamp > Config::get("login_secondsFormBlocked"));
    }

    /**
     * Checks if the current user has the specified privilege level.
     *
     * @param mixed $currentUser The current user object.
     * @param int $privilegeLevel The privilege level to be checked.
     */
    public static function checkPrivilege($currentUser, int $privilegeLevel): bool
    {
        return (!self::hasPrivilege($currentUser, $privilegeLevel));
    }

    /**
     * Checks if the current user has the specified privilege level.
     *
     * @param mixed $currentUser The current user object.
     * @param int $privilegeLevel The privilege level to be checked.
     * @return bool Returns true if the user has the specified privilege level, false otherwise.
     */
    public static function hasPrivilege($currentUser, int $privilegeLevel): bool
    {
        if (Config::get('session_on') !== TRUE) {
            throw new \Exception("Config 'session_on' must to be TRUE.");
        }
        if (!self::isLoggedIn()) {
            throw new \Exception("Current User must to be logged in.");
        }
        if ($currentUser == null) {
            return false;
        }
        return $currentUser->privilege === $privilegeLevel;
    }

    /**
     * Checks if the current user has at least the specified privilege level.
     * Lower numeric values mean higher privileges (e.g., 1 = superadmin, 2 = admin, 3 = user).
     *
     * @param mixed $currentUser The current user object.
     * @param int $privilegeLevel The minimum privilege level required.
     * @return bool Returns true if the user has at least the specified privilege level, false otherwise.
     */
    public static function hasAtLeastPrivilege($currentUser, int $privilegeLevel): bool
    {
        if (Config::get('session_on') !== TRUE) {
            throw new \Exception("Config 'session_on' must be TRUE.");
        }

        if (!self::isLoggedIn()) {
            throw new \Exception("Current User must be logged in.");
        }

        return $currentUser !== null && $currentUser->privilege !== null && $currentUser->privilege <= $privilegeLevel;
    }

    /**
     * Checks if the currently logged-in user has the specified privilege level.
     *
     * @param int $privilegeLevel The minimum required privilege level.
     * @return bool True if the user has the required (or higher) privilege level, false otherwise.
     */
    public static function currentUserIs(int $privilegeLevel): bool
    {
        try {
            if (!UserPrivilege::isValid($privilegeLevel)) {
                throw new \InvalidArgumentException("Invalid privilege level: $privilegeLevel");
            }
            $isCurrentUser = self::hasPrivilege(self::getUserLoggedObject(), $privilegeLevel);;
            return $isCurrentUser;
        } catch (\Throwable $throwable) {
            $m = "ErrorMsg: " . $throwable->getMessage();
            Logger::write("Fatal error: " . $m . "StackT: " . $throwable->getTraceAsString(), Log_Level::ERROR, Log_Driver::BOTH);
            return false;
        }
    }

    /**
     * Checks if the currently logged-in user has at least the specified privilege level.
     * Lower numeric values mean higher privileges (e.g., 1 = superadmin, 2 = admin, 3 = user).
     *
     * @param int $privilegeLevel The minimum required privilege level.
     * @return bool True if the user has the required or higher privilege level, false otherwise.
     */
    public static function currentUserIsAtLeast(int $privilegeLevel): bool
    {
        if (!UserPrivilege::isValid($privilegeLevel)) {
            throw new \InvalidArgumentException("Invalid privilege level: $privilegeLevel");
        }

        return self::hasAtLeastPrivilege(self::getUserLoggedObject(), $privilegeLevel);
    }
}
