<?php

namespace Boostack\Models\User;

use Boostack\Models\BaseList;

/**
 * Boostack: User_SSOList.php
 * ========================================================================
 * Copyright 2014-2025 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Spagnolo Stefano <s.spagnolo@hotmail.it>
 * @version 6.2
 */

class User_SSOList extends BaseList
{
    const BASE_CLASS = User_SSO::class;

    public function __construct()
    {
        parent::init();
    }
}
