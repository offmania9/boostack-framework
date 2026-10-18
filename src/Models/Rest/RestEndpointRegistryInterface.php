<?php

declare(strict_types=1);

namespace Boostack\Models\Rest;

/**
 * Application-owned allowlist and request contract for REST endpoints.
 */
interface RestEndpointRegistryInterface
{
    /** @param array<int,string> $declaredClasses @return array<string,string> */
    public static function bindings(string $namespace, array $declaredClasses): array;

    /** @param mixed $body */
    public static function validateRequest(
        string $method,
        string $contentType,
        $body,
        ?string $endpoint = null
    ): void;
}
