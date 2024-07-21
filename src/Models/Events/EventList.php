<?php

namespace Boostack\Models\Events;

use Boostack\Models\BaseList;
use Boostack\Models\Events\Event;

class EventList extends BaseList
{
    const BASE_CLASS = Event::class;

    public function __construct()
    {
        parent::init();
    }
}
