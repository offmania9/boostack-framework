<?php

namespace Boostack\Models\Events;

use Boostack\Models\BaseClassTraced;
use Boostack\Models\Session\Session;

/**
 * Boostack: Event.php
 * ========================================================================
 * Copyright 2014-2025 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Spagnolo Stefano <s.spagnolo@hotmail.it>
 * @version 6.0
 */

class Event extends BaseClassTraced
{
    protected $id_user;
    protected $name;
    protected $description;

    protected $default_values = [
        "id_user" => 0,
        "name" => '',
        "description" => NULL,
    ];

    const TABLENAME = "boostack_event";
    public function __construct($id = NULL)
    {
        parent::init($id);
    }

    public static function createFromCurrentUser($name, $description = NULL): \Boostack\Models\Events\Event
    {
        return self::create($name, $description, Session::getUserID());
    }

    public static function create($name, $description = NULL, $id_user_from = NULL,): \Boostack\Models\Events\Event
    {
        $event = new Event();
        $event->id_user = $id_user_from;
        $event->name = $name;
        $event->description = $description;
        $event->save();
        return $event;
    }

    public function notify($NotificationType = NotificationType::WEB, \DateTime $send_date = NULL): \Boostack\Models\Events\Notification
    {
        if (!NotificationType::isValid($NotificationType)) {
            throw new \Exception("NotificationType is not valid");
        }
        $notification = new Notification();
        $notification->id_user_from = $this->id_user;
        $notification->id_event = $this->id;
        $notification->type = $NotificationType;
        $notification->send_date = $send_date;
        #$notification->save();
        return $notification;
    }

    public function createNotification(array $to_user_ids, NotificationType $notificationType = NotificationType::WEB, \DateTime $send_date = NULL): void
    {
        if (count($to_user_ids) > 0) {
            $notification = new Notification();
            $notification->id_user_from = $this->id_user;
            $notification->type = $notificationType;
            $notification->send_date = $send_date;
            $notification->save();
        } else {
            throw new \Exception("sendNotification Error: user ids to send is empty");
        }
    }
}
