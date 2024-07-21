<?php

namespace Boostack\Models\Events;

use Boostack\Models\BaseList;
use Boostack\Models\Events\NotificationWeb;

class NotificationWebList extends BaseList
{
    const BASE_CLASS = NotificationWeb::class;

    public function __construct()
    {
        parent::init();
    }
}
