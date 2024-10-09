<?php

namespace Boostack\Models\Events;

use Boostack\Models\BaseList;
use Boostack\Models\Events\NotificationWeb;
use Boostack\Models\Session\Session;

/**
 * Boostack: NotificationWebList.php
 * ========================================================================
 * Copyright 2014-2024 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Spagnolo Stefano <s.spagnolo@hotmail.it>
 * @version 6.0
 */
class NotificationWebList extends BaseList
{
    const BASE_CLASS = NotificationWeb::class;

    public function __construct()
    {
        parent::init();
    }

    public function loadMyPending($num_items = 100)
    {
        $filter = array();
        $filter[] = array("status", "=", "pending");
        $filter[] = array("id_user_to", "=", Session::getUserID());
        $this->view($filter, "created_at", "desc", $num_items);
    }

    public function loadMy($num_items = 100)
    {
        $filter = array();
        $filter[] = array("id_user_to", "=", Session::getUserID());
        $this->view($filter, "created_at", "desc", $num_items);
    }
}
