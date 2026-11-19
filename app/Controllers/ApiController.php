<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ScreenplayService;
use App\Support\Csrf;
use App\Support\Http;
use RuntimeException;

final class ApiController extends BaseController
{
    public function __construct(\App\Services\AuthService $auth, private readonly ScreenplayService $screenplays)
    {
        parent::__construct($auth);
    }

    public function updateScene(string $projectUuid, string $sceneUuid): void
    {
        $user = $this->user();
        $body = Http::jsonBody();
        Csrf::verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        $field = (string) ($body['field'] ?? '');
        if (!array_key_exists('value', $body)) throw new RuntimeException('Missing field value.');
        $scene = $this->screenplays->updateScene((int) $user['id'], $projectUuid, $sceneUuid, [$field => $body['value']], isset($body['version']) ? (int) $body['version'] : null);
        Http::json(['ok' => true, 'version' => (int) $scene['lock_version'], 'value' => $scene[$field] ?? null]);
    }

    public function updateBlock(string $projectUuid, string $blockUuid): void
    {
        $user = $this->user();
        $body = Http::jsonBody();
        Csrf::verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        $field = (string) ($body['field'] ?? '');
        if (!array_key_exists('value', $body)) throw new RuntimeException('Missing field value.');
        $block = $this->screenplays->updateBlock((int) $user['id'], $projectUuid, $blockUuid, [$field => $body['value']], isset($body['version']) ? (int) $body['version'] : null);
        Http::json(['ok' => true, 'version' => (int) $block['lock_version'], 'value' => $block[$field] ?? null]);
    }
}
