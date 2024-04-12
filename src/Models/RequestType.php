<?php
namespace Boostack\Models;
/**
 * Boostack: RequestType.Class.php
 * ========================================================================
 * Copyright 2014-2024 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Spagnolo Stefano <s.spagnolo@hotmail.it>
 * @version 6.0
 */
class RequestType
{
    const QUERY = "query";
    const POST = "post";
    const REQUEST = "request";
    const COOKIE = "cookie";
    const FILES = "files";
    const SERVER = "server";
    const HEADERS = "headers";
}
