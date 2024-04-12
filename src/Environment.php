<?php

namespace Boostack;

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
            require_once($_SERVER["DOCUMENT_ROOT"] . "/vendor/boostack-framework/core/libs/helpers.php");
            Request::init();
            Config::init();

            if (Config::get('developmentMode')) {
                error_reporting(E_ALL);
                ini_set('display_errors', 1);
            } else {
                error_reporting(0);
                ini_set('display_errors', 0);
            }
            require_once($_SERVER["DOCUMENT_ROOT"] . "/my/pre_content.php");

            

            if (Config::get('database_on')) {
                Database_PDO::getInstance(Config::get('db_host'), Config::get('db_name'), Config::get('db_username'), Config::get('db_password'), Config::get('db_port'));
                if (Config::get('session_on')) {
                    Session::init();

                    // if (Config::get('cookie_on') && Request::hasCookieParam(Config::get('cookie_name')) && Request::getCookieParam(Config::get('cookie_name')) != NULL) {
                    //     //user not logged in but remember-me cookie exists then try to perform loginByCookie function
                    //     $c = Request::getCookieParam(Config::get('cookie_name'));
                    //     if (!Auth::isLoggedIn() && $c !== "")
                    //         if (!Auth::loginByCookie($c)) //cookie is set but wrong (manually edited)
                    //             Auth::logout();
                    // }
                }
            }
            if (Config::get('language_on')) {
                Language::init();
            }
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
        }
    }
}
