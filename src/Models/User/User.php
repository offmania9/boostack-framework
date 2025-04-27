<?php

namespace Boostack\Models\User;

use Boostack\Models\Database\Database_PDO;
use Boostack\Models\Log\Log_Driver;
use Boostack\Models\Log\Log_Level;
use Boostack\Models\Log\Logger;
use Boostack\Models\Request;
use Boostack\Views\View;
use Boostack\Models\Config;
use Boostack\Models\Language;
use \Firebase\JWT\JWT;

/**
 * Boostack: User.php
 * ========================================================================
 * Copyright 2014-2025 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Alessio Debernardi
 * @version 6.2
 */

class User implements \JsonSerializable
{

    protected $id;

    protected $PDO;

    protected array $objects = [
        User_Entity::class => null,
        User_Social::class => null,
        User_Registration::class => null,
        User_Info::class => null,
        #User_SSO::class => null
    ];

    protected $attributes = array();

    /**
     * Creates a new instance of the class, instantiating sub-objects.
     *
     * @param mixed|null $id
     */
    public function __construct($id = null)
    {
        $this->id = $id;
        $this->PDO = Database_PDO::getInstance();
        foreach ($this->objects as $class => &$object) {
            if (empty($object)) {
                $object = new $class();
                foreach ($object->getAttributes() as $attribute) {
                    $this->attributes[$attribute] = $class;
                }
            }
        }
    }

    /**
     * Fills the object with the key-value array passed as a parameter (invoking __get).
     * If the ID is present, it sets it in all sub-instances.
     */
    public function fill(array $array): void
    {
        if (array_key_exists("id", $array)) {
            foreach ($this->objects as $object) {
                if (is_object($object)) {
                    $object->id = (int)$array["id"];
                }
            }
        }
        foreach ($array as $attribute => $value) {
            $this->$attribute = $value;
        }
    }

    /**
     * Loads the specified ID into the object.
     *
     * @param mixed $id
     */
    public function load($id): void
    {
        $this->id = $id;
    }


    /**
     * Saves all instances to the database using a transaction.
     * If the ID is present, it invokes the save method of the sub-instances.
     * Otherwise, it saves the first instance obtaining the auto-incremented ID and then saves the other instances with the same ID.
     *
     * @param mixed|null $forcedID
     * @throws \Exception
     */
    public function save($forcedID = null): void
    {
        try {
            $this->PDO->beginTransaction();

            if (empty($this->id)) {
                $first = true;
                foreach ($this->objects as $object) {
                    if ($first) {
                        $object->save($forcedID);
                        $first = false;
                        $this->id = (int)$object->id;
                    } else {
                        $object->save($this->id);
                    }
                }
            } else {
                foreach ($this->objects as $object) {
                    if (!empty($object->id)) {
                        $object->save();
                    }
                }
            }
            $this->PDO->commit();
        } catch (\Exception $e) {
            $this->PDO->rollBack();
            Logger::write($e->getMessage(), Log_Level::ERROR, Log_Driver::FILE);
            throw $e;
        }
    }

    /**
     * Deletes all user sub-instances from the database.
     *
     * @throws \Exception If the instance does not have an 'id' field to be deleted.
     */
    public function delete(): void
    {
        if (empty($this->id)) {
            throw new \Exception("Instance must have 'id' field to be deleted");
        }
        try {
            $this->PDO->beginTransaction();
            foreach ($this->objects as $object) {
                if (is_object($object)) {
                    if (empty($object->id) && $object->exist($this->id)) {
                        $object->load($this->id);
                    }
                    $object->delete();
                }
            }
            $this->PDO->commit();
        } catch (\Exception $e) {
            $this->PDO->rollBack();
            Logger::write($e->getMessage(), Log_Level::ERROR);
        }
    }

    /**
     * Sets an attribute on the corresponding instance variable.
     * If the ID is present but the corresponding instance has not been loaded yet, it loads it.
     *
     * @param string $property The property to set.
     * @param mixed $value The value to set.
     * @throws \Exception If the field specified by $property is not found.
     */
    public function __set($property, $value)
    {
        if (!isset($this->attributes[$property])) {
            throw new \Exception("Field $property not found");
        }
        $className = $this->attributes[$property];
        $objectInstance = $this->objects[$className];
        if (!empty($this->id) && empty($objectInstance->id)) {
            $objectInstance->load($this->id);
        }
        $objectInstance->$property = $value;
    }

    /**
     * Retrieves the value of an attribute from the corresponding instance variable.
     * If the ID is present but the corresponding instance has not been loaded yet, it loads it.
     *
     * @param string $property The property to retrieve.
     * @return mixed The value of the property.
     * @throws \Exception If the field specified by $property is not found.
     */
    public function __get($property)
    {
        if ($property == "id") {
            return $this->id;
        }

        if (!isset($this->attributes[$property])) {
            throw new \Exception("Field $property not found");
        }
        $className = $this->attributes[$property];
        $objectInstance = $this->objects[$className];
        if (!empty($this->id) && (empty($objectInstance->id) || $className !== "Boostack\Models\User\User_Entity" && $className !== "Boostack\\Models\\User\\User") && $objectInstance::exist($this->id)) {
            $objectInstance->load($this->id);
        }
        if (!empty($objectInstance->$property)) {
            return $objectInstance->$property;
        }
        return "";
    }

    /**
     * Serializes the user object into a JSON-compatible array.
     *
     * @return array The serialized user data.
     */
    public function jsonSerialize(): array
    {
        return array_merge(
            $this->objects[User_Entity::class]->jsonSerialize(),
            $this->objects[User_Info::class]->jsonSerialize(),
            $this->objects[User_Social::class]->jsonSerialize(),
            $this->objects[User_Registration::class]->jsonSerialize(),
            $this->objects[User_SSO::class]->jsonSerialize()
        );
    }

    /**
     * Checks if a user with the given ID exists.
     *
     * @param int $id The user ID.
     * @param bool $throwException Whether to throw an \Exception if the user does not exist.
     * @return bool True if the user exists, false otherwise.
     */
    public static function existById(int $id, bool $throwException = true): bool
    {
        return User_Entity::existById($id, $throwException);
    }

    /**
     * Checks if a user with the given email exists.
     *
     * @param string $email The user email.
     * @param bool $throwException Whether to throw an \Exception if the user does not exist.
     * @return bool True if the user exists, false otherwise.
     */
    public static function existsByEmail(string $email, bool $throwException = true): bool
    {
        return User_Entity::existsByEmail($email, $throwException);
    }

    /**
     * Checks if a user with the given username exists.
     *
     * @param string $username The username.
     * @param bool $throwException Whether to throw an \Exception if the user does not exist.
     * @return bool True if the user exists, false otherwise.
     */
    public static function existsByUsername(string $username, bool $throwException = true): bool
    {
        return User_Entity::existsByUsername($username, $throwException);
    }

    /**
     * Retrieves the user ID associated with the given email.
     *
     * @param string $email The user email.
     * @param bool $throwException Whether to throw an \Exception if the user does not exist.
     * @return bool|int The user ID if found, false otherwise.
     */
    public static function getUserIDByEmail(string $email, bool $throwException = true)
    {
        return User_Entity::getUserIDByEmail($email, $throwException);
    }

    /**
     * Retrieves user credentials based on a cookie value.
     *
     * @param string $cookieValue The value of the cookie.
     * @return bool|array User credentials if found, false otherwise.
     */
    public static function getCredentialByCookie(string $cookieValue)
    {
        return User_Entity::getCredentialByCookie($cookieValue);
    }

    /**
     * Retrieves active user credentials by email.
     *
     * @param string $email The user email.
     * @return bool|array User credentials if found, false otherwise.
     */
    public static function getActiveCredentialByEmail(string $email)
    {
        return User_Entity::getActiveCredentialByEmail($email);
    }

    /**
     * Retrieves active user credentials by username.
     *
     * @param string $username The username.
     * @return bool|array User credentials if found, false otherwise.
     */
    public static function getActiveCredentialByUsername(string $username)
    {
        return User_Entity::getActiveCredentialByUsername($username);
    }

    /**
     * Retrieves active user credentials by email or username.
     *
     * @param string $email The user email.
     * @param string $username The username.
     * @return bool|array User credentials if found, false otherwise.
     */
    public static function getActiveCredentialByEmailOrUsername(string $email, string $username)
    {
        return User_Entity::getActiveCredentialByEmailOrUsername($email, $username);
    }

    /**
     * Retrieves the active user ID based on email and password.
     *
     * @param string $email The user email.
     * @param string $password The user password.
     * @return bool|int The user ID if found, false otherwise.
     */
    public static function getActiveIdByEmailAndPassword(string $email, string $password)
    {
        return User_Entity::getActiveIdByEmailAndPassword($email, $password);
    }

    /**
     * Retrieves the active user ID based on username and password.
     *
     * @param string $username The username.
     * @param string $password The user password.
     * @return bool|int The user ID if found, false otherwise.
     */
    public static function getActiveIdByUsernameAndPassword(string $username, string $password)
    {
        return User_Entity::getActiveIdByUsernameAndPassword($username, $password);
    }

    /**
     * Retrieves the active user ID based on email, username, and password.
     *
     * @param string $email The user email.
     * @param string $username The username.
     * @param string $password The user password.
     * @return bool|int The user ID if found, false otherwise.
     */
    public static function getActiveIdByEmailOrUsernameAndPassword(string $email, string $username, string $password)
    {
        return User_Entity::getActiveIdByEmailOrUsernameAndPassword($email, $username, $password);
    }

    /**
     * Refreshes the remember-me cookie for the user.
     */
    public function refreshRememberMeCookie(): void
    {
        $cookieHash = Request::generateCookieHash();
        $this->session_cookie = $cookieHash;
        $this->save();
        setcookie(Config::get("cookie_name"), $cookieHash, time() + Config::get("cookie_expire"), '/');
    }


    /**
     * Creates a JWT token for the user.
     */
    public function createJWTToken(?int $expirationTimestamp = null): User_ApiJWTToken
    {
        $secretKey = Config::get("api_secret_key");
        $time = time();
        $exp = $time + Config::get("api_expire");

        if ($expirationTimestamp !== null && $expirationTimestamp !== 0 && $expirationTimestamp > $time) {
            $exp = $expirationTimestamp;
        }

        $token = [
            "iss" => Config::get("url"), // Issuer of the token
            "aud" => Config::get("url"), // Audience of the token
            "iat" => $time, // Issued At time
            "nbf" => $time, // Not Before time
            "exp" => $exp, // Expiration time
            "data" => [ // Custom user data
                "id_user" => $this->id,
            ]
        ];

        $tokenEncoded = JWT::encode($token, $secretKey, 'HS256');

        $userApiJWTToken = new User_ApiJWTToken();
        $userApiJWTToken->id_user = $this->id;
        $userApiJWTToken->token = $tokenEncoded;
        $userApiJWTToken->issuer_url = $token["iss"];
        $userApiJWTToken->audience_url = $token["aud"];
        $userApiJWTToken->issued_time = $token["iat"];
        $userApiJWTToken->not_before_time = $token["nbf"];
        $userApiJWTToken->expired_time = $token["exp"];
        $userApiJWTToken->expired_timestamp = date('Y-m-d H:i:s', $token["exp"]);
        $userApiJWTToken->revoked_time = null;
        $userApiJWTToken->save();

        return $userApiJWTToken;
    }

    /**
     * Sends a confirmation email to the user.
     *
     * @param string $HTMLtemplate The HTML template for the email. Defaults to "new_pre_user.html".
     * @throws \Exception If there is an error in sending the email.
     */
    public function sendConfirmationMail(string $HTMLtemplate = "new_pre_user.html"): void
    {
        $msg = View::getMailTemplate($HTMLtemplate, [
            "help_mail" => Config::get('mail_from'),
            "fullname" => $this->first_name,
            "username" => $this->email,
            "confirm_url" => Config::get('url') . "confirm/" . $this->join_idconfirm,
            "logo" => Config::get('url') . Config::get("url_logo"),
            "hr_mail" => Config::get('mail_from'),
            "login_link" => Config::get('url')
        ]);

        if (Config::get('useMailgun')) {
            $emailMailgun = new \Boostack\Models\Email\Email_Mailgun([
                "from_mail" => Config::get("mail_from"),
                "from_name" => Config::get("name_from"),
                "bcc" => Config::get("mail_bcc"),
                "to" => $this->email,
                "subject" => Language::getLabel("email.confirmation_subject"),
                "message" => $msg
            ]);

            if (!$emailMailgun->send()) {
                throw new \Exception("Error sending confirmation email (sendConfirmationMail)");
            }
        }
    }

    /**
     * Sends a welcome email to the user.
     *
     * @param string $HTMLtemplate The HTML template for the email. Defaults to "new_user_welcome.html".
     * @throws \Exception If there is an error in sending the email.
     */
    public function sendWelcomeMail(string $HTMLtemplate = "new_user_welcome.html"): void
    {
        $msg = View::getMailTemplate($HTMLtemplate, [
            "help_mail" => Config::get('mail_from'),
            "fullname" => $this->first_name,
            "username" => $this->email,
            "logo" => Config::get('url') . Config::get("url_logo"),
            "hr_mail" => Config::get('mail_from'),
            "login_link" => Config::get('url')
        ]);

        if (Config::get('useMailgun')) {
            $emailMailgun = new \Boostack\Models\Email\Email_Mailgun([
                "from_mail" => Config::get("mail_from"),
                "from_name" => Config::get("name_from"),
                "bcc" => Config::get("mail_bcc"),
                "to" => $this->email,
                "subject" => Language::getLabel("email.welcome_subject"),
                "message" => $msg
            ]);

            if (!$emailMailgun->send()) {
                throw new \Exception("Error sending welcome email (sendWelcomeMail)");
            }
        }
    }

    /**
     * Returns the list of User_SSO objects associated with this user.
     * Each object represents a Single Sign-On (SSO) method (e.g. Google, Microsoft, etc.)
     * linked to the current user.
     *
     * @return User_SSOList List of SSO methods associated with the user
     */
    public function getMySSOs(): \Boostack\Models\User\User_SSOList
    {
        $userSSOList = new User_SSOList();
        $filter = array();
        $filter[] = array("user_id", "=", $this->id);
        $userSSOList->view($filter, "id", "desc");
        return $userSSOList;
    }
}
