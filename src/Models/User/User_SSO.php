<?php

namespace Boostack\Models\User;

use Boostack\Models\Database\Database_PDO;

/**
 * Boostack: User_SSO.php
 * ========================================================================
 * Copyright 2014-2026 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Spagnolo Stefano <s.spagnolo@hotmail.it>
 * @version 6.2
 */
class User_SSO extends \Boostack\Models\BaseClassTraced
{
    protected $user_id;

    protected $active;

    protected $provider;

    protected $provider_user_id;

    protected $name;

    protected $email;

    /**
     *
     */
    const TABLENAME = "boostack_user_sso";

    /**
     * @var array
     */
    protected $default_values = [
        "user_id" => "",
        "active" => "0",
        "provider" => "",
        "provider_user_id" => "",
        "name" => "",
        "email" => "",
        "created_at" => ""
    ];

    /**
     * User_SSO constructor.
     */
    public function __construct($id = null)
    {
        parent::init($id);
    }

    /**
     * Checks if a user exists based on their email address.
     *
     * @param string $email The email address.
     * @param bool $throwException Whether to throw an \Exception if the user is not found (default: true).
     * @return bool Whether the user exists.
     * @throws \Exception If the user is not found and $throwException is true.
     */
    public static function existsByEmail($email, $throwException = true): bool
    {
        $PDO = Database_PDO::getInstance();
        $query = "SELECT id FROM " . self::TABLENAME . " WHERE email = :email";
        $q = $PDO->prepare($query);
        $q->bindParam(":email", $email);
        $q->execute();
        if ($q->rowCount() == 0) {
            if ($throwException) {
                throw new \Exception("User doesn't exists by email.", 3);
            }
            return false;
        }
        return true;
    }

    /**
     * Retrieves the user ID associated with a given email address.
     *
     * @param string $email The email address.
     * @param bool $throwException Whether to throw an \Exception if the email is not found (default: true).
     * @return int|false The user ID if the email is found, false otherwise.
     * @throws \Exception If the email is not found and $throwException is true.
     */
    public static function getUserIDByEmail($email, $provider = null, $throwException = true)
    {
        $PDO = Database_PDO::getInstance();
        $sql = empty($provider) ? "SELECT id FROM " . static::TABLENAME . " WHERE email = :email" : "SELECT id FROM " . static::TABLENAME . " WHERE email = :email AND provider= :provider AND active='1'";
        $q = $PDO->prepare($sql);
        $q->bindValue(':email', $email);
        if (!empty($provider)) {
            $q->bindValue(':provider', $provider);
        }
        $q->execute();
        $q2 = $q->fetch();
        if ($q->rowCount() == 0) {
            if ($throwException) {
                throw new \Exception("Attention! User or Email not found.", 0);
            }
            return false;
        }
        return $q2[0];
    }
}
