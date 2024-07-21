<?php

namespace Boostack\Models\Events;

use Boostack\Models\BaseClassTraced;
use Boostack\Models\Config;
use Boostack\Models\Log\Log_Level;
use Boostack\Models\Log\Logger;

class Notification extends BaseClassTraced
{
    protected $id_event;
    protected $id_user_from;
    protected $type;
    protected $send_date;

    protected $message_content;
    protected $email_content;

    protected $default_values = [
        "id_event" => 0,
        "id_user_from" => 0,
        "type" => 'web',
        "send_date" => NULL,
    ];

    const TABLENAME = "boostack_notification";
    public function __construct($id = NULL)
    {
        $this->custom_excluded = array("message_content", "email_content");
        parent::init($id);
    }

    public function enqueue(array $to_user_ids)
    {
        if (count($to_user_ids) > 0) {
            $this->save();
            if ($this->type == NotificationType::WEB || $this->type == NotificationType::ALL) {
                if (empty($this->message_content)) {
                    throw new \Exception("Notification Web Error: message_content is empty. Use Notification setMessageContent function");
                }
                foreach ($to_user_ids as $id_user_to) {
                    $not = new NotificationWeb();
                    $not->id_notification = $this->id;
                    $not->id_user_to = $id_user_to;
                    $not->status = 'pending';
                    $not->message_content = $this->message_content;
                    $not->save();
                }
            }
            if ($this->type == NotificationType::EMAIL || $this->type == NotificationType::ALL) {
                if (empty($this->email_content)) {
                    throw new \Exception("Notification Web Error: email_content is empty. Use Notification setEmailContent function");
                }
                foreach ($to_user_ids as $id_user_to) {
                    $user = new \Boostack\Models\User\User($id_user_to);
                    $not = new NotificationEmail();
                    $not->id_notification = $this->id;
                    $not->id_user_to = $id_user_to;
                    $not->email_to = $user->email;
                    $not->status = 'pending';
                    $not->email_content = $this->email_content;
                    $not->retries = 0;
                    $not->max_retries = Config::get("notification_email_max_retries") == -1 ? NULL : Config::get("notification_email_max_retries");
                    $not->sent_at = $this->send_date;
                    $not->save();
                }
            }
            Logger::write("Notification with ID: " . $this->id . " sended in database", Log_Level::INFORMATION);
            return $this;
        } else {
            throw new \Exception("send notification Error: user ids array is empty");
        }
    }

    // public function send()
    // {
    //     if ($this->type == NotificationType::EMAIL || $this->type == NotificationType::ALL)
    //         NotificationEmailSender::trigger();
    // }

    public function setMessageContent($message_content)
    {
        $this->message_content = $message_content;
        return $this;
    }

    public function setEmailContent($email_content)
    {
        $this->email_content = $email_content;
        return $this;
    }
}
