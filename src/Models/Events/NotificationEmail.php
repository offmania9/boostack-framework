<?php

namespace Boostack\Models\Events;

use Boostack\Models\BaseClassTraced;

class NotificationEmail extends BaseClassTraced
{
    protected $id_notification;
    protected $id_user_to;
    protected $email_to;
    protected $status;
    protected $email_content;
    protected $retries;
    protected $max_retries;
    protected $sent_at;

    protected $default_values = [
        "id_notification" => 0,
        "id_user_to" => 0,
        "email_to" => '',
        "status" => 'pending',
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
