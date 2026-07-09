<?php

namespace Boostack\Models\Upload;

use Boostack\Models\BaseClassTraced;
use Boostack\Models\Upload\Upload_File;

class Asset extends BaseClassTraced
{
    protected $object_name;
    protected $object_type;
    protected $temp_name;
    protected $filename;
    protected $filepath;
    protected $type;
    protected $size;
    protected $extension;
    protected $file_hash_sha256;
    protected $upload_source;
    protected $source_context;
    protected $created_by;

    protected $default_values = [
        "object_name" => NULL,
        "object_type" => NULL,
        "temp_name" => '',
        "filename" => '',
        "filepath" => '',
        "type" => '',
        "size" => 0,
        "extension" => '',
        "file_hash_sha256" => NULL,
        "upload_source" => NULL,
        "source_context" => NULL,
        "created_by" => NULL,
    ];

    const TABLENAME = "boostack_asset";
    public function __construct($id = NULL)
    {
        parent::init($id);
    }

    public function loadFromFile(Upload_File $uploadFile): void
    {
        $this->temp_name = $uploadFile->name;
        $this->filename = $uploadFile->name;
        $this->type = $uploadFile->type;
        $this->size = $uploadFile->size;
        $this->extension = $uploadFile->extension;
    }
}
