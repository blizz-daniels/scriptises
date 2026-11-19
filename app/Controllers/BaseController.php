<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Support\Csrf;
use App\Support\Http;
use RuntimeException;

abstract class BaseController
{
    public function __construct(protected readonly AuthService $auth)
    {
    }

    /** @return array{id:int,uuid:string,email:string} */
    protected function user(): array
    {
        return $this->auth->user() ?? Http::redirect('/login');
    }

    protected function csrf(?string $token = null): void
    {
        Csrf::verify($token ?? ($_POST['_csrf'] ?? null));
    }

    protected function requestText(string $key): string
    {
        return trim((string) ($_POST[$key] ?? ''));
    }

    protected function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            throw new RuntimeException('Method not allowed.');
        }
    }
}
