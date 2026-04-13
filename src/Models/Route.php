<?php

namespace Boostack\Models;

/**
 * Boostack: Route.php
 * ========================================================================
 * Copyright 2014-2026 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Stefano Spagnolo
 * @version 6.2
 */

class Route implements \JsonSerializable
{
    public string $pattern;
    public $target;
    public string $method;
    public array $paramNames;

    public function __construct(string $pattern, $target, string $method = 'GET', array $paramNames = [])
    {
        $this->pattern = $pattern;
        $this->target = $target;
        $this->method = strtoupper($method);
        $this->paramNames = $paramNames;
    }

    public function matches(string $uri, string $requestMethod): ?array
    {
        if ($this->method !== strtoupper($requestMethod)) {
            return null;
        }

        if (preg_match('#^' . $this->pattern . '$#', $uri, $matches)) {
            array_shift($matches); // Rimuove il match completo
            $params = [];

            foreach ($matches as $index => $value) {
                $paramName = $this->paramNames[$index] ?? $index;
                $params[$paramName] = $value;
            }

            return $params;
        }

        return null;
    }

    /**
     * This method is used when json_encode() is called.
     * It exposes all the variables of the object to the json_encode() function.
     *
     * @return mixed Returns an array of object variables to serialize.
     */
  public function jsonSerialize(): mixed
    {
        // Se target è una closure, sostituisco con un placeholder
        if ($this->target instanceof \Closure) {
            $target = 'closure_not_serializable';
        } elseif (is_array($this->target) && isset($this->target[0]) && $this->target[0] instanceof \Closure) {
            // Nel caso sia un array con closure (es. [Closure, 'method'])
            $target = 'closure_not_serializable';
        } else {
            $target = $this->target;
        }

        return [
            'pattern' => $this->pattern,
            'target' => $target,
            'method' => $this->method,
            'paramNames' => $this->paramNames,
        ];
    }

    public static function fromArray(array $data): self
    {
        // Se target è placeholder, lo trasformo in null o un target di default
        $target = $data['target'] ?? null;
        if ($target === 'closure_not_serializable') {
            $target = null; // oppure qualche callable di default
        }

        return new self(
            $data['pattern'],
            $target,
            $data['method'] ?? 'GET',
            $data['paramNames'] ?? []
        );
    }
}
