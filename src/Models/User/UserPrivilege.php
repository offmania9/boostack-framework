<?php

namespace Boostack\Models\User;

/**
 * Boostack: UserPrivilege.php
 * ========================================================================
 * Copyright 2014-2025 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Spagnolo Stefano <s.spagnolo@hotmail.it>
 * @version 6.2
 */
class UserPrivilege
{
    public const SYSTEM = 0;
    public const SUPERADMIN = 1;
    public const ADMIN = 2;
    public const USER = 3;

    private function __construct() {}

    public static function isValid(int $value): bool
    {
        $values = [self::SYSTEM, self::SUPERADMIN, self::ADMIN, self::USER];
        return in_array($value, $values, true);
    }

    /**
     * Returns the name of the constant corresponding to the given value.
     *
     * @param int $value
     * @return string
     * @throws \InvalidArgumentException If the value does not correspond to any constant
     */
    public static function getConstantName(int $value): string
    {
        $constants = [
            self::SYSTEM      => 'SYSTEM',
            self::SUPERADMIN  => 'SUPERADMIN',
            self::ADMIN       => 'ADMIN',
            self::USER        => 'USER'
        ];
        if (!isset($constants[$value])) {
            throw new \InvalidArgumentException("Invalid value for UserPrivilege: " . $value);
        }
        return $constants[$value];
    }
}
