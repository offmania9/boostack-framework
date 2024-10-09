<?php

namespace Boostack\Models\Events;

use Boostack\Models\BaseList;
use Boostack\Models\Events\Notification;

/**
 * Boostack: NotificationList.php
 * ========================================================================
 * Copyright 2014-2024 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Spagnolo Stefano <s.spagnolo@hotmail.it>
 * @version 6.0
 */
class NotificationList extends BaseList
{
    const BASE_CLASS = Notification::class;

    public function __construct()
    {
        parent::init();
    }
}
