<?php

declare(strict_types=1);

namespace App\Support;

final class Http
{
    public static function redirect(string $path): never
    {
        header('Location: ' . $path, true, 302);
        exit;
    }

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_THROW_ON_ERROR);
        exit;
    }

    public static function path(): string
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
        if ($base !== '' && $base !== '/' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }

        return rtrim($path, '/') ?: '/';
    }

    /** @return array<string, mixed> */
    public static function jsonBody(): array
    {
        $body = file_get_contents('php://input');
        if ($body === false || $body === '') {
            return [];
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }
}
