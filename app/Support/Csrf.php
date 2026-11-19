<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf'];
    }

    public static function verify(?string $token): void
    {
        if (!is_string($token) || !hash_equals((string) ($_SESSION['_csrf'] ?? ''), $token)) {
            throw new RuntimeException('Your form expired. Please reload and try again.');
        }
    }
}
