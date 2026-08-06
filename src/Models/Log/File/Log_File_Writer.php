<?php

namespace Boostack\Models\Log\File;

use Boostack\Models\Config;
use Boostack\Models\Log\Log_Level;

/**
 * Boostack: Log_File_Writer.php
 * ========================================================================
 * Copyright 2014-2025 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Alessio Debernardi
 * @version 6.0
 */

/**
 * Class Log_File_Writer
 *
 * This class provides functionality to write log messages to a file.
 */
class Log_File_Writer
{
    /** @var Log_File_Writer|null The singleton instance of the class. */
    private static ?\Boostack\Models\Log\File\Log_File_Writer $logFileWriter = NULL;

    /** @var array<string,int> Tracks repeated error/warning entries within the same request. */
    private static array $duplicateCounts = [];

    /** @var array<string,array{level:string,message:string}> Stores the first occurrence for duplicate summaries. */
    private static array $duplicateEntries = [];

    /** @var bool Ensures duplicate summary flush is registered only once per request. */
    private static bool $shutdownRegistered = false;

    /** @var string The path to the log file. */
    private string $logFile;

    /**
     * Log_File_Writer constructor.
     *
     * Initializes the log file path.
     */
    private function __construct()
    {
        $path = \ROOTPATH . Config::get("log_dir");
        if (!file_exists($path)) {
            exit("Error: unable to find log dir: $path");
        }
        if (!is_writable($path)) {
            exit("Error: log dir must be writable");
        }
        $filename = "boostack-" . date("Y-m-d") . ".log";
        $this->logFile = $path . $filename;
        self::registerShutdownFlush();
    }

    /**
     * Retrieves the singleton instance of the class.
     *
     * @return Log_File_Writer The singleton instance of Log_File_Writer.
     */
    public static function getInstance(): \Boostack\Models\Log\File\Log_File_Writer
    {
        if (self::$logFileWriter == NULL) {
            self::$logFileWriter = new Log_File_Writer();
        }

        return self::$logFileWriter;
    }

    /**
     * Writes a log message to the log file.
     *
     * @param string|null $message The log message to write.
     * @param int $level The level of the log message.
     * @throws \Exception Throws an \Exception if unable to open the log file.
     */
    public function log($message = NULL, $level = Log_Level::INFORMATION): void
    {
        $date = new \DateTime();
        $formattedDate = $date->format(\DateTime::ATOM);
        $message = $this->normalizeMessage($message);
        $message = $this->attachRequestContext($message);

        if ($this->shouldSkipDuplicate($level, $message)) {
            return;
        }

        $this->writeLine($formattedDate, $level, $message);
    }

    private function normalizeMessage($message): string
    {
        if (is_string($message)) {
            return $message;
        }

        if ($message === null || is_scalar($message)) {
            return (string)$message;
        }

        $json = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json !== false ? $json : print_r($message, true);
    }

    private function attachRequestContext(string $message): string
    {
        $context = $this->buildRequestContext();
        if ($context === []) {
            return $message;
        }

        $decoded = json_decode($message, true);
        if (is_array($decoded) && !array_is_list($decoded)) {
            $existingContext = [];
            if (isset($decoded['context']) && is_array($decoded['context'])) {
                $existingContext = $decoded['context'];
            }
            $decoded['context'] = $existingContext + $context;

            $json = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $json !== false ? $json : $message;
        }

        $payload = [
            'message' => $message,
            'context' => $context,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json !== false ? $json : $message;
    }

    private function buildRequestContext(): array
    {
        if (!$this->hasHttpRequestContext()) {
            return [];
        }

        $context = [];
        $server = (isset($_SERVER) && is_array($_SERVER)) ? $_SERVER : [];
        $request = (isset($_REQUEST) && is_array($_REQUEST)) ? $_REQUEST : [];
        $method = $server['REQUEST_METHOD'] ?? null;
        $requestUri = $server['REQUEST_URI'] ?? null;
        $referer = $server['HTTP_REFERER'] ?? null;
        $remoteAddr = $server['REMOTE_ADDR'] ?? null;

        if (is_string($method) && $method !== '') {
            $context['method'] = $method;
        }
        if (is_string($requestUri) && $requestUri !== '') {
            $context['request_uri'] = $requestUri;
        }
        if (is_string($referer) && $referer !== '') {
            $context['referer'] = $referer;
        }
        if (is_string($remoteAddr) && $remoteAddr !== '') {
            $context['remote_addr'] = $remoteAddr;
        }
        if (isset($request['path']) && is_scalar($request['path']) && (string)$request['path'] !== '') {
            $context['path'] = (string)$request['path'];
        }
        if (isset($request['route']) && is_scalar($request['route']) && (string)$request['route'] !== '') {
            $context['route'] = (string)$request['route'];
        }

        return $context;
    }

    private function hasHttpRequestContext(): bool
    {
        if (in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
            return false;
        }

        $server = (isset($_SERVER) && is_array($_SERVER)) ? $_SERVER : [];
        if (($server['REQUEST_METHOD'] ?? '') !== '') {
            return true;
        }

        if (($server['REQUEST_URI'] ?? '') !== '') {
            return true;
        }

        if (($server['HTTP_HOST'] ?? '') !== '') {
            return true;
        }

        return false;
    }

    private function shouldSkipDuplicate(string $level, string $message): bool
    {
        if (!in_array($level, [Log_Level::ERROR, Log_Level::WARNING], true)) {
            return false;
        }

        $key = sha1($level . "\n" . $message);
        if (!isset(self::$duplicateCounts[$key])) {
            self::$duplicateCounts[$key] = 1;
            self::$duplicateEntries[$key] = [
                'level' => $level,
                'message' => $message,
            ];
            return false;
        }

        self::$duplicateCounts[$key]++;
        return true;
    }

    private function writeLine(string $formattedDate, string $level, string $message): void
    {
        $logFile = fopen($this->logFile, "a");
        if ($logFile == false) {
            throw new \Exception("Error: Unable to open log file");
        }

        fwrite($logFile, "[" . $formattedDate . "] [" . $level . "] " . $message . "\n");
        fclose($logFile);
    }

    private static function registerShutdownFlush(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;
        register_shutdown_function([self::class, 'flushDuplicateSummaries']);
    }

    public static function flushDuplicateSummaries(): void
    {
        $instance = self::$logFileWriter;
        if ($instance === null) {
            return;
        }

        foreach (self::$duplicateCounts as $key => $count) {
            if ($count <= 1 || !isset(self::$duplicateEntries[$key])) {
                continue;
            }

            $entry = self::$duplicateEntries[$key];
            $payload = [
                'message' => 'Suppressed duplicate log entries within the same request',
                'context' => [
                    'duplicates_suppressed' => $count - 1,
                    'original_level' => $entry['level'],
                    'original_message' => $entry['message'],
                ],
            ];

            $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $instance->writeLine((new \DateTime())->format(\DateTime::ATOM), Log_Level::WARNING, $encodedPayload !== false ? $encodedPayload : 'Suppressed duplicate log entries within the same request');
        }
    }
}
