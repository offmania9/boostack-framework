<?php

namespace Boostack\Models\Events;

use Boostack\Models\BaseList;
use Boostack\Models\Events\Notification;

class NotificationList extends BaseList
{
    const BASE_CLASS = Notification::class;

    public function __construct()
    {
        parent::init();
    }
}
