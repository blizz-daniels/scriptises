<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\ApiController;
use App\Controllers\AuthController;
use App\Controllers\ProjectController;
use App\Controllers\PdfController;
use App\Controllers\ManagementController;
use App\Controllers\TransferController;
use App\Services\AuthService;
use App\Services\ManagementService;
use App\Services\PdfExport;
use App\Services\ProjectTransfer;
use App\Services\ScreenplayService;
use App\Support\Flash;
use App\Support\Http;
use App\Support\View;

$auth = new AuthService($database);
$screenplays = new ScreenplayService($database);
$management = new ManagementService($database, $screenplays);
$pdfController = new PdfController($auth, $screenplays, $management, new PdfExport($screenplays, $management));
$managementController = new ManagementController($auth, $screenplays, $management);
$transferController = new TransferController($auth, $screenplays, $management, new ProjectTransfer($database, $screenplays, $management));
$authController = new AuthController($auth);
$projectController = new ProjectController($auth, $screenplays, $management);
$apiController = new ApiController($auth, $screenplays);
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = Http::path();

try {
    if ($method === 'GET' && $path === '/') { $projectController->dashboard(); exit; }
    if ($method === 'GET' && $path === '/setup') { $authController->setup(); exit; }
    if ($method === 'POST' && $path === '/setup') { $authController->createInitialUser(); exit; }
    if ($method === 'GET' && $path === '/login') { $authController->loginForm(); exit; }
    if ($method === 'POST' && $path === '/login') { $authController->login(); exit; }
    if ($method === 'POST' && $path === '/logout') { $authController->logout(); exit; }
    if ($method === 'POST' && $path === '/projects') { $projectController->createProject(); exit; }
    if ($method === 'GET' && $path === '/import') { $transferController->show(); exit; }
    if ($method === 'GET' && $path === '/import/source') { $transferController->original(); exit; }
    if ($method === 'POST' && $path === '/import/stage') { $transferController->stage(); exit; }
    if ($method === 'POST' && $path === '/import/confirm') { $transferController->confirm(); exit; }
    if ($method === 'POST' && $path === '/import/cancel') { $transferController->cancel(); exit; }

    if ($method === 'GET' && preg_match('#^/projects/([a-f0-9-]{36})/(search|characters)$#i', $path, $matches)) {
        $managementController->find($matches[1], $matches[2] === 'characters'); exit;
    }
    if (preg_match('#^/projects/([a-f0-9-]{36})/plots$#i', $path, $matches)) {
        if ($method === 'GET') { $managementController->plots($matches[1]); exit; }
        if ($method === 'POST') { $managementController->savePlot($matches[1]); exit; }
    }
    if ($method === 'POST' && preg_match('#^/projects/([a-f0-9-]{36})/plots/([a-f0-9-]{36})/delete$#i', $path, $matches)) { $managementController->deletePlot($matches[1], $matches[2]); exit; }
    if ($method === 'POST' && preg_match('#^/projects/([a-f0-9-]{36})/characters/([a-f0-9-]{36})$#i', $path, $matches)) { $managementController->saveCharacter($matches[1], $matches[2]); exit; }
    if ($method === 'POST' && preg_match('#^/projects/([a-f0-9-]{36})/scenes/([a-f0-9-]{36})/characters$#i', $path, $matches)) { $managementController->linkCharacter($matches[1], $matches[2]); exit; }
    if (preg_match('#^/projects/([a-f0-9-]{36})/blocks/([a-f0-9-]{36})/history$#i', $path, $matches)) {
        if ($method === 'GET') { $managementController->history($matches[1], $matches[2]); exit; }
        if ($method === 'POST') { $managementController->restoreBlock($matches[1], $matches[2]); exit; }
    }
    if ($method === 'GET' && preg_match('#^/projects/([a-f0-9-]{36})/export$#i', $path, $matches)) { $transferController->export($matches[1]); exit; }
    if (in_array($method, ['GET', 'POST'], true) && preg_match('#^/projects/([a-f0-9-]{36})/pdf$#i', $path, $matches)) { $pdfController->show($matches[1], $method === 'POST'); exit; }
    if ($method === 'POST' && preg_match('#^/projects/([a-f0-9-]{36})/pdf/download$#i', $path, $matches)) { $pdfController->download($matches[1]); exit; }
    if ($method === 'GET' && preg_match('#^/projects/([a-f0-9-]{36})/imports/(\d+)$#i', $path, $matches)) { $transferController->source($matches[1], (int) $matches[2]); exit; }

    if (preg_match('#^/projects/([a-f0-9-]{36})$#i', $path, $matches)) {
        if ($method === 'GET') { $projectController->workspace($matches[1]); exit; }
        if ($method === 'POST') { $projectController->updateProject($matches[1]); exit; }
    }
    if ($method === 'POST' && preg_match('#^/projects/([a-f0-9-]{36})/archive$#i', $path, $matches)) { $projectController->archiveProject($matches[1]); exit; }
    if ($method === 'POST' && preg_match('#^/projects/([a-f0-9-]{36})/delete$#i', $path, $matches)) { $projectController->deleteProject($matches[1]); exit; }
    if ($method === 'POST' && preg_match('#^/projects/([a-f0-9-]{36})/episodes$#i', $path, $matches)) { $projectController->createEpisode($matches[1]); exit; }
    if ($method === 'POST' && preg_match('#^/projects/([a-f0-9-]{36})/acts$#i', $path, $matches)) { $projectController->createAct($matches[1]); exit; }
    if ($method === 'POST' && preg_match('#^/projects/([a-f0-9-]{36})/characters$#i', $path, $matches)) { $projectController->createCharacter($matches[1]); exit; }
    if ($method === 'POST' && preg_match('#^/projects/([a-f0-9-]{36})/scenes$#i', $path, $matches)) { $projectController->createScene($matches[1]); exit; }
    if ($method === 'GET' && preg_match('#^/projects/([a-f0-9-]{36})/read$#i', $path, $matches)) { $projectController->read($matches[1]); exit; }
    if ($method === 'GET' && preg_match('#^/projects/([a-f0-9-]{36})/scenes/([a-f0-9-]{36})$#i', $path, $matches)) { $projectController->editor($matches[1], $matches[2]); exit; }
    if ($method === 'POST' && preg_match('#^/projects/([a-f0-9-]{36})/scenes/([a-f0-9-]{36})/duplicate$#i', $path, $matches)) { $projectController->duplicateScene($matches[1], $matches[2]); exit; }
    if ($method === 'POST' && preg_match('#^/projects/([a-f0-9-]{36})/scenes/([a-f0-9-]{36})/blocks$#i', $path, $matches)) { $projectController->createBlock($matches[1], $matches[2]); exit; }
    if ($method === 'POST' && preg_match('#^/projects/([a-f0-9-]{36})/scenes/([a-f0-9-]{36})/blocks/([a-f0-9-]{36})/delete$#i', $path, $matches)) { $projectController->deleteBlock($matches[1], $matches[2], $matches[3]); exit; }
    if ($method === 'POST' && preg_match('#^/projects/([a-f0-9-]{36})/scenes/([a-f0-9-]{36})/blocks/([a-f0-9-]{36})/move$#i', $path, $matches)) { $projectController->moveBlock($matches[1], $matches[2], $matches[3]); exit; }
    if ($method === 'POST' && preg_match('#^/projects/([a-f0-9-]{36})/items/(episode|act|scene)/([a-f0-9-]{36})/move$#i', $path, $matches)) { $projectController->moveItem($matches[1], $matches[2], $matches[3]); exit; }
    if ($method === 'POST' && preg_match('#^/projects/([a-f0-9-]{36})/items/(episode|act|character)/([a-f0-9-]{36})/rename$#i', $path, $matches)) { $projectController->renameItem($matches[1], $matches[2], $matches[3]); exit; }
    if ($method === 'POST' && preg_match('#^/projects/([a-f0-9-]{36})/items/(episode|act|scene)/([a-f0-9-]{36})/delete$#i', $path, $matches)) { $projectController->deleteItem($matches[1], $matches[2], $matches[3]); exit; }

    if (in_array($method, ['PATCH', 'POST'], true) && preg_match('#^/api/projects/([a-f0-9-]{36})/scenes/([a-f0-9-]{36})$#i', $path, $matches)) { $apiController->updateScene($matches[1], $matches[2]); exit; }
    if (in_array($method, ['PATCH', 'POST'], true) && preg_match('#^/api/projects/([a-f0-9-]{36})/blocks/([a-f0-9-]{36})$#i', $path, $matches)) { $apiController->updateBlock($matches[1], $matches[2]); exit; }

    http_response_code(404);
    View::render('error', ['pageTitle' => 'Page not found', 'message' => 'The page you requested does not exist.', 'user' => $auth->user()]);
} catch (Throwable $exception) {
    $isApi = str_starts_with($path, '/api/');
    $status = str_contains($exception->getMessage(), 'another tab') ? 409 : 422;
    if ($isApi) {
        Http::json(['ok' => false, 'error' => $exception->getMessage()], $status);
    }

    if ($method === 'POST') {
        Flash::set('error', $exception->getMessage());
        $fallback = $auth->user() === null ? '/login' : '/';
        $referer = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
        Http::redirect(is_string($referer) && str_starts_with($referer, '/') ? $referer : $fallback);
    }

    http_response_code(500);
    View::render('error', [
        'pageTitle' => 'Something needs attention',
        'message' => $exception->getMessage(),
        'user' => $auth->user(),
    ]);
}
