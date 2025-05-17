<?php

/**
 * Boostack: Router.php
 * ========================================================================
 * Copyright 2014-2025 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Stefano Spagnolo
 * @version 6.2
 */

namespace Boostack\Controllers;

use Boostack\Models\Config;
use Boostack\Models\Request;
use Boostack\Models\Route;
use Boostack\Models\HttpMethod;
use Boostack\Models\StatusCodes;

class Router implements \JsonSerializable
{
    /** @var Route[] List of registered routes */
    private array $routes = [];

    /**
     * Register a new route.
     *
     * @param string $pattern Regular expression pattern (without delimiters).
     * @param callable|string|array $target Callback, controller, or file to be executed.
     * @param string|HttpMethod $method HTTP method (GET, POST, etc.), default is GET.
     * @param array $paramNames List of parameter names to extract from regex matches.
     * @return void
     */
    public function addRoute(string $pattern, callable|string|array $target, string|HttpMethod $method = HttpMethod::GET, array $paramNames = []): void
    {
        if ($method instanceof HttpMethod) {
            $method = $method->value;
        }
        $this->routes[] = new Route($pattern, $target, strtoupper($method), $paramNames);
    }

    /**
     * Dispatch the route based on the requested URI.
     *
     * @param string $uri The requested URI (e.g., $_SERVER['REQUEST_URI']).
     * @return void
     */

    public function dispatch(string $uri): void
    {
        try {
            $method = Request::getMethod()->value;
            $uri = trim(parse_url($uri, PHP_URL_PATH), '/');

            foreach ($this->routes as $route) {
                if ($route->method !== $method) {
                    continue;
                }
                if (preg_match('#^' . $route->pattern . '$#', $uri, $matches)) {
                    array_shift($matches);
                    $params = [];

                    foreach ($route->paramNames as $index => $name) {
                        $value = $matches[$index] ?? null;
                        $params[$name] = $value;
                        Request::setQueryParam($name, $value);
                    }

                    // foreach ($params as $name => $value) {

                    //     match ($method) {
                    //         'POST' => Request::setPostParam($name, $value),
                    //         'PUT', 'DELETE', 'PATCH' => Request::setRequestParam($name, $value),
                    //         default => null,
                    //     };
                    // }

                    if (is_callable($route->target)) {
                        call_user_func_array($route->target, []);
                    } elseif (is_array($route->target) && is_callable($route->target)) {
                        call_user_func_array($route->target, []);
                    } elseif (is_string($route->target) && file_exists($route->target)) {
                        require $route->target;
                    } else {
                        $this->sendError(StatusCodes::HTTP_INTERNAL_SERVER_ERROR, "Invalid route target. (if routing cache is enabled check it!)");
                    }

                    return;
                }
            }
            $this->sendError(StatusCodes::HTTP_NOT_FOUND, "404 Not Found");
        } catch (\Throwable $e) {
            $this->sendError(StatusCodes::HTTP_INTERNAL_SERVER_ERROR, "Unexpected error: " . $e->getMessage());
        }
    }

    /**
     * Send an HTTP error response using StatusCodes helper.
     *
     * @param int $code HTTP status code.
     * @param string|null $message Optional message to display.
     * @return void
     */
    private function sendError(int $code, ?string $message = null): void
    {
        header(StatusCodes::getHttpHeaderFor($code));
        if (StatusCodes::canHaveBody($code)) {
            echo $message ?? StatusCodes::getMessageForCode($code);
        }
        if (!Config::get("developmentMode"))
            Request::goToError($code);
        exit;
    }

    /**
     * This method is used when json_encode() is called.
     * It exposes all the variables of the object to the json_encode() function.
     *
     * @return mixed Returns an array of object variables to serialize.
     */
    public function jsonSerialize(): mixed
    {
        return [
            'routes' => $this->routes
        ];
    }

    public static function fromArray(array $data): Router
    {
        $router = new Router();

        foreach ($data['routes'] as $r) {
            $route = Route::fromArray($r);
            $router->addRoute($route->pattern, $route->target, $route->method, $route->paramNames);
        }


        return $router;
    }
}
