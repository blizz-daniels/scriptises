<?php

declare(strict_types=1);

namespace App\Support;

final class View
{
    /** @param array<string, mixed> $data */
    public static function render(string $template, array $data = []): void
    {
        $viewPath = dirname(__DIR__) . '/Views/' . $template . '.php';
        if (!is_file($viewPath)) {
            throw new \RuntimeException("View [$template] does not exist.");
        }

        extract($data, EXTR_SKIP);
        require $viewPath;
    }

    public static function escape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
