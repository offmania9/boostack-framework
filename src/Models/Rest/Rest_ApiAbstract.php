<?php

namespace Boostack\Models\Rest;

use Boostack\Models\Utils\Utils;
use Boostack\Models\Request;
use Boostack\Models\StatusCodes;
use Boostack\Models\Config;
use Boostack\Models\MessageBag;
use Boostack\Models\Auth;
use Boostack\Models\Log\Logger;
use Boostack\Models\Log\Log_Level;
use Boostack\Exceptions\Exception_Validation;

/**
 * Boostack: Rest_Api_Abstract.php
 * ========================================================================
 * Copyright 2014-2025 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Spagnolo Stefano <s.spagnolo@hotmail.it>
 * @version 6.0
 */

abstract class Rest_ApiAbstract
{

    protected static $outputNoLogged = false;

    protected $method = '';

    protected string $endpoint;

    protected string $verb = '';

    protected $args = array();

    protected $content_type = "";

    protected $request;

    protected $file;

    protected \Boostack\Models\MessageBag $messageBag;

    protected \Boostack\Models\Rest\Rest_ApiRequest $apiRequest;

    /**
     * Constructor for the Rest_Api_Abstract class.
     *
     * @param $requestedMethod
     * @throws \Exception
     */
    public function __construct($requestedMethod)
    {
        Config::constraint("api_on");
        $this->apiRequest = new Rest_ApiRequest();
        $this->messageBag = new MessageBag();
        $this->messageBag->error = false;
        $this->method = Request::getServerParam('REQUEST_METHOD');

        // Allow for CORS
        header("Access-Control-Allow-Orgin: *");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        header("Content-Type: application/json");
        header("Access-Control-Allow-Headers: Content-Type, Origin, Access-Control-Allow-Headers, Authorization, X-Requested-With");

        switch ($this->method) {
            case 'DELETE':
            case 'POST':
                if (array_key_exists('HTTP_X_HTTP_METHOD', $_SERVER)) {
                    if ($_SERVER['HTTP_X_HTTP_METHOD'] == 'PUT') {
                        $fs = trim(file_get_contents("php://input"));
                        $f = null;
                        $this->file = parse_str($fs, $f);
                        $this->file = Request::sanitizeInput($this->file);
                        $this->request = Request::getQueryArray();
                    } else {
                        throw new \Exception("Unexpected Header");
                    }
                } else {
                    $this->file = empty(Request::getFilesArray()) ? trim(file_get_contents("php://input")) : Request::getFilesArray();
                    $this->request = Request::getPostArray();
                }
                break;
            case 'GET':
                $this->request = Request::getQueryArray();
                break;
            case 'OPTIONS':
                header(StatusCodes::getHttpHeaderFor(StatusCodes::HTTP_OK));
                die();
            default:
                $this->_setErrorMessageObject('Invalid Method', StatusCodes::HTTP_METHOD_NOT_ALLOWED);
                break;
        }

        $this->args = explode('/', rtrim($requestedMethod, '/'));
        $this->endpoint = array_shift($this->args);
        if (array_key_exists(0, $this->args) && !is_numeric($this->args[0])) {
            $this->verb = array_shift($this->args);
        }
    }

    /**
     * Process the API request.
     *
     * @return string
     */
    public function processAPI()
    {
        $startedAt = microtime(true);
        $requestId = $this->generateRequestId();
        header('X-Request-Id: ' . $requestId);

        try {
            if (!Request::checkAcceptedTimeFromLastRequest()) {
                throw new \Boostack\Exceptions\Exception_APITooManyRequests("Too many requests. Please wait a few seconds.");
            }

            $methodBindings = [];
            $subclasses = [];
            $dir = Config::get("api_my_extended_classes_dir");
            $namespace = Config::get("api_my_extended_namespace");
            $declaredClasses = [];
            $this->getDirContents($dir, $declaredClasses);

            foreach ($declaredClasses as $class) {
                $classReflection = new \ReflectionClass($namespace . $class);
                if ($classReflection->isSubclassOf('\Boostack\Models\Rest\Rest_ApiAbstract')) {
                    $subclasses[] = $class;
                }
            }

            foreach ($subclasses as $subclass) {
                $subclass = new \ReflectionClass($namespace . $subclass);
                $methods = $subclass->getMethods(\ReflectionMethod::IS_PROTECTED);
                foreach ($methods as $method) {
                    $methodBindings[$method->name] = $method->class;
                }
            }

            if (isset($methodBindings[$this->endpoint])) {
                $class = $methodBindings[$this->endpoint];
                $classInstance = new $class("");
                $this->trackRequest();
                $this->apiRequest->save();
                $this->messageBag->data = $classInstance->{$this->endpoint}($this->args);
                if (empty($this->messageBag->code)) {
                    $this->messageBag->code = $this->messageBag->error
                        ? StatusCodes::HTTP_BAD_REQUEST
                        : StatusCodes::HTTP_OK;
                }
            } else {
                throw new \Boostack\Exceptions\Exception_APINotFound("No Endpoint: " . $this->endpoint . ". The resource you requested doesn't exist. For more info, please refer to the documentation.");
            }
        } catch (\Boostack\Exceptions\Exception_APITooManyRequests $e) {
            $this->_setErrorMessageObject("API Too many requests", StatusCodes::HTTP_TOO_MANY_REQUEST, $e->getMessage());
        } catch (\Boostack\Exceptions\Exception_APINotFound $e) {
            $this->_setErrorMessageObject("API not found", StatusCodes::HTTP_NOT_FOUND, $e->getMessage());
        } catch (Exception_Validation $e) {
            $code = (int) $e->getCode();
            if ($code < 400 || $code > 599) {
                $code = StatusCodes::HTTP_BAD_REQUEST;
            }
            $this->_setErrorMessageObject("Validation error", $code, $e->getMessage());
        } catch (\Exception $e) {
            $this->_setErrorMessageObject("Process API method error", StatusCodes::HTTP_INTERNAL_SERVER_ERROR, $e->getMessage());
        } finally {
            $this->trackRequest();
            $this->apiRequest->save();
            $this->logApiExecution($startedAt, $requestId);
        }

        header(StatusCodes::getHttpHeaderFor($this->messageBag->code));

        return $this->messageBag->toJSON();
    }

    /**
     * Set error message object.
     *
     * @param $message
     * @param $code
     */
    private function _setErrorMessageObject(string $message, int $code, $data = null): void
    {
        $this->messageBag->error = true;
        $this->messageBag->code = $code;
        $this->messageBag->message = $message;
        $this->messageBag->data = $data;
    }

    /**
     * Track the API request details.
     */
    private function trackRequest(): void
    {
        $this->apiRequest->method = $this->method;
        $this->apiRequest->endpoint = $this->endpoint;
        $this->apiRequest->verb = $this->verb;
        $this->apiRequest->get_args = isset(Request::getQueryArray()["request"]) ? Request::getQueryArray()["request"] : "";
        $this->apiRequest->post_args = json_encode(Request::getPostArray());
        $this->apiRequest->file_args = json_encode($this->file);
        $this->apiRequest->remote_address = Request::getIpAddress();
        $this->apiRequest->remote_user_agent = Request::getUserAgent();
        $this->apiRequest->error = $this->messageBag->error ? 1 : 0;
        $this->apiRequest->code = $this->messageBag->code;
        $this->apiRequest->message = $this->messageBag->message;

        $this->apiRequest->output = static::$outputNoLogged ? "no-logged" : json_encode($this->messageBag->data);
    }

    /**
     * Emit a structured API log enriched with status code, duration and request id.
     */
    private function logApiExecution(float $startedAt, string $requestId): void
    {
        $statusCode = (int) ($this->messageBag->code ?? StatusCodes::HTTP_INTERNAL_SERVER_ERROR);
        if ($statusCode <= 0) {
            $statusCode = StatusCodes::HTTP_INTERNAL_SERVER_ERROR;
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $level = $this->resolveLogLevelFromStatus($statusCode);
        $actionTag = $this->resolveActionTag();
        $message = $this->messageBag->message ?: ('API endpoint: ' . $this->endpoint);

        Logger::write([
            'message' => $message,
            'context' => [
                'action_tag' => $actionTag,
                'module' => 'API',
                'module_key' => 'api',
                'navigation_key' => $this->endpoint ?: 'api',
                'route' => Request::getServerParam('REQUEST_URI'),
                'endpoint' => $this->endpoint,
                'method' => $this->method,
                'status_code' => $statusCode,
                'duration_ms' => $durationMs,
                'request_id' => $requestId,
                'is_api' => 1,
            ],
        ], $level);
    }

    private function resolveLogLevelFromStatus(int $statusCode): string
    {
        if ($statusCode >= 500) {
            return Log_Level::ERROR;
        }
        if ($statusCode >= 400) {
            return Log_Level::WARNING;
        }
        return Log_Level::USER;
    }

    private function resolveActionTag(): string
    {
        $method = strtoupper((string) $this->method);
        $probe = strtolower(trim($this->endpoint . ' ' . $this->verb));
        $action = Request::hasPostParam('action') ? strtolower(trim((string) Request::getPostParam('action'))) : '';
        if ($action !== '') {
            $probe .= ' ' . $action;
        }

        if (strpos($probe, 'import') !== false) {
            return 'import';
        }
        if (strpos($probe, 'export') !== false) {
            return 'export';
        }
        if (strpos($probe, 'search') !== false || strpos($probe, 'filter') !== false || strpos($probe, 'find') !== false || strpos($probe, 'lookup') !== false) {
            return 'search';
        }
        if ($method === 'DELETE' || strpos($probe, 'delete') !== false || strpos($probe, 'remove') !== false) {
            return 'delete';
        }
        if ($method === 'PATCH' || $method === 'PUT' || strpos($probe, 'update') !== false || strpos($probe, 'edit') !== false || strpos($probe, 'save') !== false) {
            return 'update';
        }
        if ($method === 'POST' && (strpos($probe, 'create') !== false || strpos($probe, 'new') !== false || strpos($probe, 'add') !== false || strpos($probe, 'plan') !== false)) {
            return 'create';
        }
        if ($method === 'GET' && (strpos($probe, 'list') !== false || strpos($probe, 'all') !== false)) {
            return 'list';
        }
        if ($statusCode = (int) ($this->messageBag->code ?? 0)) {
            if ($statusCode >= 500) {
                return 'error';
            }
            if ($statusCode >= 400) {
                return 'warning';
            }
        }
        return 'api_call';
    }

    private function generateRequestId(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return uniqid('api_', true);
        }
    }

    /**
     * Apply constraints on the API method.
     *
     * @param $method
     * @throws \Exception
     */
    protected function constraints(
        string $method,
        bool $currentUserIsLogged = false,
        ?array $headers = null,
        ?array $serverParams = null,
        bool $fileIsJSON = true
    ) {
        if (strcasecmp($this->method, $method) !== 0) {
            throw new \Exception("Only accepts $method requests.");
        }

        if ($currentUserIsLogged && !Auth::isLoggedIn()) {
            throw new \Exception("Only accepts requests from already logged in user.");
        }

        // Server params
        if (!empty($serverParams)) {
            foreach ($serverParams as $key => $value) {
                if (!Request::hasServerParam($key)) {
                    throw new \Exception("Server param '$key' must be set.");
                }
                if ($value !== "*" && strcasecmp(Request::getServerParam($key), $value) !== 0) {
                    throw new \Exception("Server param '$key' must be set to: $value");
                }
            }
        }

        // Header params
        if (!empty($headers)) {
            foreach ($headers as $key => $value) {
                $keyLower = strtolower($key);

                if (!Request::hasHeaderParam($keyLower)) {
                    throw new \Exception("Header '$key' must be set.");
                }

                if ($value !== "*" && strcasecmp(Request::getHeaderParam($keyLower), $value) !== 0) {
                    throw new \Exception("Header '$key' must be set to: $value");
                }
            }
        }

        if ($fileIsJSON && (!empty($this->file) && !Utils::isJson($this->file))) {
            throw new \Exception('Received content contained invalid JSON!');
        }
    }

    /**
     * Recursively get all files in a directory.
     *
     * @param $dir
     * @param array $results
     * @return array
     */
    private function getDirContents(string $dir, &$results = [])
    {
        $files = scandir($dir);
        foreach ($files as $file) {
            $path = realpath($dir . DIRECTORY_SEPARATOR . $file);
            if (!is_dir($path)) {
                $results[] = basename($path, ".php");
            } elseif ($file !== "." && $file !== "..") {
                $this->getDirContents($path, $results);
            }
        }
        return $results;
    }
}
