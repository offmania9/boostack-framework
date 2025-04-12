<?php

namespace Boostack;

use Boostack\Exception\Exception_TooManyRequests;
use Boostack\Models\Config;
use Boostack\Models\Request;
use Boostack\Models\Database\Database_PDO;
use Boostack\Models\Session\Session;
use Boostack\Models\Language;
use Boostack\Models\Log\Log_Driver;
use Boostack\Models\Log\Log_Level;
use Boostack\Models\Log\Logger;

class Environment
{
    public static function init()
    {
        try {
            Request::init();
            Config::init();

            if (Config::get('developmentMode')) {
                error_reporting(E_ALL);
                ini_set('display_errors', 1);
            } else {
                error_reporting(0);
                ini_set('display_errors', 0);
            }
            if (ini_get('session.cookie_secure') && (!Request::hasServerParam("HTTPS") || Request::getServerParam("HTTPS") === 'off')) {
                ini_set('session.cookie_secure', '0');
            }

            if (Config::get('database_on')) {
                Database_PDO::getInstance(Config::get('db_host'), Config::get('db_name'), Config::get('db_username'), Config::get('db_password'), Config::get('db_port'));
                if (Config::get('session_on')) {
                    Session::init();
                }
            }
            if (Config::get('language_on')) {
                Language::init();
            }
            if (!Request::hasServerParam("DOCUMENT_ROOT"))
                throw new \Exception("The DOCUMENT_ROOT environment variable is not set.");

            require_once(Request::getServerParam("DOCUMENT_ROOT") . "/my/pre_content.php");
        } catch (Exception_TooManyRequests $e) {
            $short_message = "System error. See log files.";
            $message = "<br/>" . $short_message;
            $message .= "<br/>" . $e->getMessage();
            $message .= "<br/>Check 'seconds_accepted_between_requests' config in .env file";
            $message .= "<br/>" . $e->getTraceAsString();
            Logger::write($message, Log_Level::ERROR, Log_Driver::FILE);
            if (Config::get("developmentMode")) {
                echo $message;
            } else {
                echo $e->getMessage();
            }
            exit();
        } catch (\PDOException $e) {
            $short_message = "Database error. See log files.";
            $message = $short_message . $e->getMessage() . $e->getTraceAsString() . "\n";
            Logger::write($message, Log_Level::ERROR, Log_Driver::FILE);
            if (Config::get("developmentMode")) {
                echo $message;
            } else {
                echo $short_message;
            }
            exit();
        } catch (\Exception $e) {
            $short_message = "System error. See log files.";
            $message = $short_message . $e->getMessage() . $e->getTraceAsString() . "\n";
            Logger::write($message, Log_Level::ERROR, Log_Driver::FILE);
            if (Config::get("developmentMode")) {
                echo $message;
            } else {
                echo $short_message;
            }
            exit();
        }
    }
}
