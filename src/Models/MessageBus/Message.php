<?php

namespace Boostack\Models\MessageBus;

class Message extends \Boostack\Models\BaseClassTraced
{

// ******* USAGE **************
// $message = new \Boostack\Models\MessageBus\Message();
// $callable = function($param1, $param2) {
//     Logger::write("messaggio di test. params:".$param1." - ". $param2,Log_Level::INFORMATION,Log_Driver::FILE);
// };
// $message->enqueue("example_queue",$callable,['param1', 'param2']);
// *********************

    protected $queue_name;
    protected $callable;
    protected $params;
    protected $retries;
    protected $max_retries;
    protected $executed_at;

    protected $default_values = [
        "queue_name" => "",
        "callable" => "",
        "params" => "",
        "retries" => 0,
        "max_retries" => NULL,
        "executed_at" => NULL
    ];

    const TABLENAME = "boostack_message_queue";

    /**
     * Constructor.
     *
     * @param mixed|null $id The ID of the object.
     */
    public function __construct($id = NULL)
    {
        parent::init($id);
    }

    public function enqueue($queue_name, $callable, $params, $max_retries = -1)
    {
        $this->callable = serialize($callable);
        $this->params = serialize($params);
        $this->queue_name = $queue_name;
        $this->max_retries = $max_retries;
        $this->save();
    }
}
