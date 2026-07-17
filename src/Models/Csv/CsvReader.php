<?php
namespace Boostack\Models\Csv;
/**
 * Boostack: CsvReader.php
 * ========================================================================
 * Copyright 2014-2026 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Alessio Debernardi
 * @version 4
 */

class CsvReader
{

    const DEFAULT_DELIMITER = ",";
    const DEFAULT_ENCLOSURE = '"';
    const DEFAULT_ESCAPE = "\\";
    const DEFAULT_LINES_OFFSET = 0;
    const DEFAULT_HEADING_LINE = -1;

    private string $filePath;
    private $fileInstance;
    private $delimiter = self::DEFAULT_DELIMITER;
    private $linesOffset = self::DEFAULT_LINES_OFFSET;
    private int $rowIndex = 0;

    public function __construct($file, $delimiter = self::DEFAULT_DELIMITER, $linesOffset = self::DEFAULT_LINES_OFFSET)
    {
        $realPath = realpath($file);
        if (!$realPath) {
            throw new \Exception("File not found");
        }
        if (!is_readable($realPath)) {
            throw new \Exception("File not readable");
        }
        $this->filePath = $realPath;
        $this->delimiter = $delimiter;
        $this->linesOffset = $linesOffset;
    }

    /**
     * @return list<list<(string | null)>>
     */
    public function fetchAll(): array
    {
        $fileHandler = $this->openFile($this->filePath);
        $result = array();
        $rowCount = 0;
        if ($this->linesOffset > 0) {
            while ($rowCount < $this->linesOffset && ($row = fgetcsv($fileHandler, 0, $this->delimiter, self::DEFAULT_ENCLOSURE, self::DEFAULT_ESCAPE)) !== false) {
                $rowCount++;
            }
        }
        while (($row = fgetcsv($fileHandler, 0, $this->delimiter, self::DEFAULT_ENCLOSURE, self::DEFAULT_ESCAPE)) !== false) {

            $result[] = $row;
            $rowCount++;
        }
        return $result;
    }

    public function fetchRow()
    {
        if ($this->fileInstance == null) {
            $this->fileInstance = $this->openFile($this->filePath);
            if ($this->linesOffset > 0) {
                while ($this->rowIndex < $this->linesOffset && ($row = fgetcsv($this->fileInstance, 0, $this->delimiter, self::DEFAULT_ENCLOSURE, self::DEFAULT_ESCAPE)) !== false) {
                    $this->rowIndex++;
                }
            }
        }
        $row = fgetcsv($this->fileInstance, 0, $this->delimiter, self::DEFAULT_ENCLOSURE, self::DEFAULT_ESCAPE);
        $this->rowIndex++;
        return $row;
    }

    private function openFile(string $path)
    {
        $fileHandler = fopen($path, "r");
        if ($fileHandler == false) {
            throw new \Exception("Failed to open file");
        }
        return $fileHandler;
    }
}
