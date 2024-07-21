<?php

namespace Boostack\Models\Events;

use Boostack\Models\BaseClassTraced;

class NotificationWeb extends BaseClassTraced
{
    protected $id_notification;
    protected $id_user_to;
    protected $status;
    protected $message_content;

    protected $notification_obj;

    protected $default_values = [
        "id_notification" => 0,
        "id_user_to" => 0,
        "status" => 'pending',
        "message_content" => NULL,
    ];

    const TABLENAME = "boostack_notification_web";
    public function __construct($id = NULL)
    {
        $this->custom_excluded[] = "notification_obj";
        parent::init($id);
        if ($id !== NULL)
            $this->notification_obj = new Notification($this->id_notification);
    }

    public function messageContent($message_content)
    {
        $this->message_content = $message_content;
    }
}
