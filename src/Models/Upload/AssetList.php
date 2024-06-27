<?php

namespace My\Models;

use Boostack\Models\BaseList;
use My\Models\Asset;

class AssetList extends BaseList
{
    const BASE_CLASS = Asset::class;

    public function __construct()
    {
        parent::init();
    }
}
