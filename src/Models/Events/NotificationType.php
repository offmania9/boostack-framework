<?php
namespace Boostack\Models\Events;
/**
 * Boostack: NotificationType.php
 * ========================================================================
 * Copyright 2014-2024 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Spagnolo Stefano <s.spagnolo@hotmail.it>
 * @version 6.0
 */

class NotificationType {
    public const WEB = 'web';
    public const EMAIL = 'email';
    public const ALL = 'all';

    private function __construct() {
    }

    public static function isValid(string $value): bool {
        $values = [self::WEB, self::EMAIL, self::ALL];
        return in_array($value, $values, true);
    }
}