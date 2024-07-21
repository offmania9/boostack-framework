<?php

namespace Boostack\Models\User;

/**
 * Boostack: UserPrivilege.php
 * ========================================================================
 * Copyright 2014-2024 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Spagnolo Stefano <s.spagnolo@hotmail.it>
 * @version 6.0
 */
class UserPrivilege
{
    public const SYSTEM = 0;
    public const SUPERADMIN = 1;
    public const ADMIN = 2;
    public const USER = 3;

    private function __construct()
    {
    }

    public static function isValid(string $value): bool
    {
        $values = [self::SYSTEM, self::SUPERADMIN, self::ADMIN, self::USER];
        return in_array($value, $values, true);
    }
}