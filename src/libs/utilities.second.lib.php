<?php
use Boostack\Models\Database\Database_PDO;
/**
 * Boostack: utilities.second.lib.php
 * ========================================================================
 * Copyright 2014-2024 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Spagnolo Stefano <s.spagnolo@hotmail.it>
 * @version 6.0
 */
function textescaping($text, $minlenght, $maxlenght, $newlinereplace)
{
    $text = trim($text);
    $text = substr($text, 0, $maxlenght);
    $text = preg_replace("([^ ]{85})", $newlinereplace, $text);
    $text = str_replace(array(
        "\r\n",
        "\n",
        "\r"
    ), $newlinereplace, $text);
    $text = addslashes($text);
    return $text;
}

function datetime_format_string_to_sqlformat($string_with_slash)
{
    $array_data = explode("/", $string_with_slash);
    return $array_data[2] . "-" . $array_data[1] . "-" . $array_data[0];
}

/**
 * Formats a datetime string from SQL format to slashed format (dd/mm/yyyy).
 *
 * @param string $datetime_sql Datetime string in SQL format (yyyy-mm-dd).
 * @return string Formatted datetime string in slashed format (dd/mm/yyyy).
 */
function datetime_format_string_to_slashedformat($datetime_sql)
{
    // Split the datetime string into an array using "-" as the delimiter
    $array_data = explode("-", $datetime_sql);

    // Reorder the array elements to form the slashed format (dd/mm/yyyy)
    return $array_data[2] . "/" . $array_data[1] . "/" . $array_data[0];
}


/**
 * Formats a date string from SQL format to slashed format (dd/mm/yyyy).
 *
 * @param string $date_sql Date string in SQL format (yyyy-mm-dd).
 * @return string Formatted date string in slashed format (dd/mm/yyyy).
 */
function date_format_string_to_slashedformat($date_sql)
{
    $array_data = explode("-", $date_sql);
    return $array_data[2] . "/" . $array_data[1] . "/" . $array_data[0];
}

/**
 * Gets the current date and time in SQL format (yyyy-mm-dd HH:MM:SS).
 *
 * @return string Current date and time in SQL format.
 */
function getDateTime()
{
    $PDO = Database_PDO::getInstance();
    $datetime = $PDO->query("SELECT NOW() as datetime_now");
    $data = $datetime->fetch();
    return $data['datetime_now'];
}

/**
 * Gets the current date in SQL format (yyyy-mm-dd).
 *
 * @return string Current date in SQL format.
 */
function getDateN()
{
    $PDO = Database_PDO::getInstance();
    $date = $PDO->query("SELECT CURDATE() as date_now");
    $data = $date->fetch();
    return $data['date_now'];
}

/**
 * Gets the current time in SQL format (HH:MM:SS).
 *
 * @return string Current time in SQL format.
 */
function getTimeN()
{
    $PDO = Database_PDO::getInstance();
    $time = $PDO->query("SELECT CURTIME() as time_now");
    $data = $time->fetch();
    return $data['time_now'];
}


/**
 * Get the month from the current date.
 *
 * @return int The month of the current date.
 */
function getDateN_Month()
{
    $date = getDateN();
    return (int) explode("-", $date)[1];
}

/**
 * Get the day from the current date.
 *
 * @return int The day of the current date.
 */
function getDateN_Day()
{
    $date = getDateN();
    return (int) explode("-", $date)[2];
}

/**
 * Get the year from the current date.
 *
 * @return int The year of the current date.
 */
function getDateN_Year()
{
    $date = getDateN();
    return (int) explode("-", $date)[0];
}

/**
 * Get the timestamp from a datetime string.
 *
 * @param string $datetime_sql The datetime string in SQL format (yyyy-mm-dd HH:MM:SS).
 * @return int|false The timestamp of the datetime string, or false if the input is invalid.
 */
function getDateTimeTimestamp($datetime_sql)
{
    $datetime_parts = explode(' ', $datetime_sql);
    if (count($datetime_parts) !== 2) {
        return false; // Invalid datetime format
    }

    list($date, $time) = $datetime_parts;
    list($year, $month, $day) = explode('-', $date);
    list($hour, $minute, $second) = explode(':', $time);

    // Check if the datetime parts are valid
    if (!checkdate($month, $day, $year) || $hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59) {
        return false; // Invalid datetime components
    }

    $timestamp = mktime($hour, $minute, $second, $month, $day, $year);
    return $timestamp;
}
/**
 * Get the elapsed time since the specified datetime timestamp.
 *
 * @param int $datetime_timestamp The datetime timestamp.
 * @return string The elapsed time formatted as a human-readable string.
 */
function getElapsedTime($datetime_timestamp)
{
    $current_timestamp = time();
    $elapsed_time = $current_timestamp - $datetime_timestamp;

    if ($elapsed_time <= 60) {
        return "$elapsed_time seconds ago";
    } elseif ($elapsed_time <= 3600) {
        $minutes = (int) ($elapsed_time / 60);
        return ($minutes > 1) ? "$minutes minutes ago" : "$minutes minute ago";
    } elseif ($elapsed_time < 86400) {
        $hours = (int) ($elapsed_time / 3600);
        return ($hours > 1) ? "$hours hours ago" : "$hours hour ago";
    } else {
        $days = (int) ($elapsed_time / 86400);
        return ($days > 1) ? "$days days ago" : "$days day ago";
    }
}
