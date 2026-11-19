<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\DocxReader;
use App\Services\FountainParser;
use App\Services\ManagementService;
use App\Services\PasteParser;
use App\Services\ProjectDocument;
use App\Services\ProjectTransfer;
use App\Services\ScreenplayService;
use App\Support\Flash;
use App\Support\Http;
use App\Support\View;
use RuntimeException;

final class TransferController extends BaseController
{
    private const PENDING = 'screenplay_import';

    public function __construct(
        AuthService $auth,
        private readonly ScreenplayService $scripts,
        private readonly ManagementService $management,
        private readonly ProjectTransfer $transfer
    ) {
        parent::__construct($auth);
    }

    public function show(): void
    {
        $user = $this->user();
        $pending = $_SESSION[self::PENDING] ?? null;
        $requestedProject = is_string($_GET['project'] ?? null) ? $_GET['project'] : '';
        if ($pending && $requestedProject !== ($pending['destination'] ?? '')) {
            Http::redirect(self::importPath($pending['destination'] ?? '') . '#review');
        }
        $catalog = $requestedProject === '' ? [] : $this->scripts->catalog((int) $user['id'], $requestedProject);
        View::render('import', [
            'user' => $user,
            'pageTitle' => 'Import screenplay',
            'projects' => $catalog ? [] : $this->scripts->projectsForUser((int) $user['id'], true),
            'catalog' => $catalog,
            'pending' => $pending,
            'pasteText' => $_SESSION['import_paste_text'] ?? '',
            'pasteTitle' => $_SESSION['import_paste_title'] ?? '',
        ]);
        unset($_SESSION['import_paste_text'], $_SESSION['import_paste_title']);
    }

    public function stage(): void
    {
        $user = $this->user();
        $this->csrf();
        $format = $this->requestText('format');
        $destination = $this->requestText('project');
        $project = $destination === '' ? null : $this->scripts->project((int) $user['id'], $destination);
        $path = self::importPath($destination);
        if ($project !== null && $format !== 'json') {
            throw new RuntimeException('Choose a JSON file to import into this project.');
        }
        if ($format === 'paste') {
            $source = is_string($_POST['screenplay'] ?? null) ? $_POST['screenplay'] : '';
            $title = $this->requestText('title');
            try {
                $draft = PasteParser::parse($title, $source);
            } catch (RuntimeException $error) {
                if (strlen($source) <= 2_000_000) {
                    $_SESSION['import_paste_text'] = $source;
                    $_SESSION['import_paste_title'] = mb_substr($title, 0, 255);
                }
                Flash::set('error', $error->getMessage());
                Http::redirect($path);
            }
        } elseif (in_array($format, ['file', 'json', 'docx', 'fountain'], true)) {
            try {
                [$format, $source, $draft] = $this->stageFile($project);
            } catch (RuntimeException $error) {
                Flash::set('error', $error->getMessage());
                Http::redirect($path);
            }
        } else {
            throw new RuntimeException('Choose a screenplay file or paste text.');
        }
        $_SESSION[self::PENDING] = $draft + ['destination' => $destination, 'source' => $source, 'format' => $format, 'token' => bin2hex(random_bytes(24))];
        Http::redirect($path . '#review');
    }

    private function stageFile(?array $project): array
    {
        $file = $_FILES['backup'] ?? [];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)
            || !is_uploaded_file($file['tmp_name']) || $file['size'] > ProjectDocument::MAX_BYTES) {
            throw new RuntimeException('Choose a readable file within your server’s upload limit (up to 8 MB).');
        }
        $name = basename(str_replace('\\', '/', $file['name']));
        $format = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($project !== null && $format !== 'json') {
            throw new RuntimeException('Choose a .json file.');
        }
        if (!in_array($format, ['json', 'docx', 'fountain'], true)) {
            throw new RuntimeException('Choose a .json, .docx, or .fountain file.');
        }
        $source = file_get_contents($file['tmp_name']);
        if ($source === false || $source === '') {
            throw new RuntimeException('The uploaded file is empty or unreadable.');
        }
        $title = mb_substr(trim(mb_scrub(pathinfo($name, PATHINFO_FILENAME))), 0, 255) ?: 'Imported screenplay';
        if ($format === 'docx') {
            $read = DocxReader::read($file['tmp_name']);
            $draft = PasteParser::parse($title, $read['text'], $read['hints']) + ['preview' => $read['text']];
        } elseif ($format === 'fountain') {
            $draft = FountainParser::parse($source, $title);
        } else {
            $defaults = $project === null ? null : [
                'title' => $project['title'],
                'type' => $project['project_type'],
                'status' => $project['status'],
            ];
            $draft = ['document' => ProjectDocument::decode($source, $defaults), 'flags' => []];
        }
        return [$format, $source, $draft];
    }

    public function confirm(): void
    {
        $user = $this->user();
        $this->csrf();
        $pending = $_SESSION[self::PENDING] ?? null;
        if (!$pending || !hash_equals($pending['token'], $this->requestText('draft_token'))) {
            throw new RuntimeException('This review has expired. Start the import again.');
        }
        $review = $_POST['proposal'] ?? '';
        $project = $pending['destination'] ?? '';
        $destination = $project !== '' ? $project : $this->requestText('destination');
        $scope = $project !== '' ? $this->requestText('scope') : '';
        $reviewPath = self::importPath($project) . '#review';
        if (!preg_match('/^(?:new|[0-9a-f-]{36})$/D', $destination)) {
            throw new RuntimeException('Choose a new project or an existing destination.');
        }
        try {
            $document = is_string($review) && $review !== '' ? ProjectDocument::decode($review) : $pending['document'];
            $_SESSION[self::PENDING]['document'] = $document;
            $_SESSION[self::PENDING]['scope'] = $scope;
            if ($project !== '' && !$document['scenes'] && !$document['acts']) {
                throw new RuntimeException('The JSON file must contain an act or a scene.');
            }
            $uuid = $this->transfer->restore((int) $user['id'], $document, $pending['source'], $pending['format'], $destination === 'new' ? null : $destination, $scope);
        } catch (\PDOException $error) {
            error_log('Screenplay import rolled back. Database code: ' . $error->getCode());
            Flash::set('error', 'The import could not be saved. Check for duplicate character names or invalid ordering.');
            Http::redirect($reviewPath);
        } catch (RuntimeException $error) {
            if (is_string($review) && strlen($review) <= ProjectDocument::MAX_BYTES) {
                $_SESSION[self::PENDING]['review'] = $review;
            }
            Flash::set('error', $error->getMessage());
            Http::redirect($reviewPath);
        }
        unset($_SESSION[self::PENDING]);
        Flash::set('success', $project === '' ? 'Screenplay imported.' : 'JSON added to the project.');
        Http::redirect("/projects/$uuid");
    }

    public function cancel(): void
    {
        $this->user();
        $this->csrf();
        $destination = $_SESSION[self::PENDING]['destination'] ?? '';
        unset($_SESSION[self::PENDING]);
        Flash::set('success', 'Import draft discarded.');
        Http::redirect(self::importPath($destination));
    }

    private static function importPath(string $project): string
    {
        return $project === '' ? '/import' : '/import?project=' . rawurlencode($project);
    }

    public function original(): void
    {
        $this->user();
        $pending = $_SESSION[self::PENDING] ?? throw new RuntimeException('No import draft is open.');
        $this->downloadSource($pending['source'], $pending['format'], 'original-screenplay');
    }

    public function export(string $uuid): void
    {
        $user = $this->user();
        $selection = null;
        if (($_GET['scope'] ?? '') === 'filtered') {
            $rows = $this->management->search((int) $user['id'], $uuid, ManagementController::filters($_GET), true);
            $selection = isset($rows[0]['block_uuid']) ? ['blocks' => array_column($rows, 'block_uuid')] : ['scenes' => array_column($rows, 'scene_uuid')];
        } elseif (($_GET['scope'] ?? '') === 'selected') {
            $selection = [];
            foreach (['blocks', 'scenes'] as $key) {
                $selection[$key] = array_filter(is_array($_GET[$key] ?? null) ? $_GET[$key] : [], 'is_string');
            }
        }
        $document = $this->transfer->backup((int) $user['id'], $uuid, $selection);
        $this->download(
            json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'scriptises-' . $uuid . ($selection === null ? '-backup.json' : '-selection.json'),
            'application/json'
        );
    }

    public function source(string $uuid, int $id): void
    {
        $user = $this->user();
        $project = $this->scripts->project((int) $user['id'], $uuid);
        $record = $this->management->rows('SELECT source_type, original_source FROM import_records WHERE id = ? AND project_id = ?', [$id, $project['id']])[0]
            ?? throw new RuntimeException('The original source was not found.');
        $this->downloadSource($record['original_source'], $record['source_type'], 'original-screenplay-' . $id);
    }

    private function downloadSource(string $payload, string $format, string $filename): never
    {
        $extension = $format === 'paste' ? 'txt' : $format;
        $mime = match ($format) {
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'json' => 'application/json; charset=utf-8',
            default => 'text/plain; charset=utf-8',
        };
        $this->download($payload, "$filename.$extension", $mime);
    }

    private function download(string $payload, string $filename, string $type): never
    {
        header("Content-Type: $type");
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $payload;
        exit;
    }
}
