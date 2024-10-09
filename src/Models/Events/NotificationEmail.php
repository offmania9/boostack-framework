<?php

namespace Boostack\Models\Events;

use Boostack\Models\BaseClassTraced;

/**
 * Boostack: NotificationEmail.php
 * ========================================================================
 * Copyright 2014-2024 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Spagnolo Stefano <s.spagnolo@hotmail.it>
 * @version 6.0
 */
class NotificationEmail extends BaseClassTraced
{
    protected $id_notification;
    protected $id_user_to;
    protected $email_to;
    protected $status;
    protected $json_object;
    protected $email_content;
    protected $retries;
    protected $max_retries;
    protected $sent_at;

    protected $default_values = [
        "id_notification" => 0,
        "id_user_to" => 0,
        "email_to" => '',
        "status" => 'pending',
        "json_object" => NULL,
        "email_content" => '',
        "retries" => 0,
        "max_retries" => NULL,
        "sent_at" => NULL,
    ];

    const TABLENAME = "boostack_notification_email";
    public function __construct($id = NULL)
    {
        parent::init($id);
    }
}
