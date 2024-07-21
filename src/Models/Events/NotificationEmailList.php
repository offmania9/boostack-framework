<?php

namespace Boostack\Models\Events;

use Boostack\Models\BaseList;
use Boostack\Models\Events\NotificationEmail;

class NotificationEmailList extends BaseList
{
    const BASE_CLASS = NotificationEmail::class;

    public function __construct()
    {
        parent::init();
    }
}
