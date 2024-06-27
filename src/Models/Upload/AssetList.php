<?php

namespace Boostack\Models\Upload;

use Boostack\Models\BaseList;
use Boostack\Models\Upload\Asset;

class AssetList extends BaseList
{
    const BASE_CLASS = Asset::class;

    public function __construct()
    {
        parent::init();
    }
}
