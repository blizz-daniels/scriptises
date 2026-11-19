<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\ManagementService;
use App\Services\PdfExport;
use App\Services\ScreenplayService;
use App\Support\Flash;
use App\Support\Http;
use App\Support\View;
use RuntimeException;

final class PdfController extends BaseController
{
    private const PREVIEW = 'screenplay_pdf_preview';

    public function __construct(
        AuthService $auth,
        private readonly ScreenplayService $scripts,
        private readonly ManagementService $management,
        private readonly PdfExport $pdf
    ) {
        parent::__construct($auth);
    }

    public function show(string $uuid, bool $posted): void
    {
        $user = $this->user();
        if ($posted) {
            $this->csrf();
        }
        $userId = (int) $user['id'];
        $data = $this->scripts->catalog($userId, $uuid);
        $id = (int) $data['project']['id'];
        $options = self::options($posted ? $_POST : $_GET);
        $html = $token = $summary = $error = null;
        if ($posted || isset($_GET['scope'])) {
            try {
                $document = $this->pdf->collect($userId, $uuid, $options);
                $html = $this->pdf->html($document);
                $summary = $document['label'] . ' · ' . count($document['scenes']) . (count($document['scenes']) === 1 ? ' scene' : ' scenes');
                $token = bin2hex(random_bytes(24));
                $_SESSION[self::PREVIEW] = [
                    'project' => $uuid, 'token' => $token, 'options' => $options,
                    'digest' => hash('sha256', $html), 'expires' => time() + 1800,
                ];
            } catch (RuntimeException $failure) {
                if ($failure instanceof \PDOException) {
                    throw $failure;
                }
                $error = $failure->getMessage();
            }
        }
        View::render('pdf', $data + [
            'user' => $user, 'pageTitle' => 'Export PDF', 'pageStylesheet' => '/assets/pdf-layout.css',
            'outline' => $this->pdf->outline($id), 'plots' => $this->management->plots($id),
            'options' => $options, 'previewHtml' => $html, 'previewToken' => $token,
            'previewSummary' => $summary, 'previewError' => $error,
        ]);
    }

    public function download(string $uuid): void
    {
        $user = $this->user();
        $this->csrf();
        $preview = $_SESSION[self::PREVIEW] ?? null;
        if (!$preview || $preview['project'] !== $uuid || $preview['expires'] < time() || !hash_equals($preview['token'], $this->requestText('preview'))) {
            Flash::set('error', 'Create a fresh preview before downloading the PDF.');
            Http::redirect("/projects/$uuid/pdf");
        }
        $document = $this->pdf->collect((int) $user['id'], $uuid, $preview['options']);
        $html = $this->pdf->html($document);
        if (!hash_equals($preview['digest'], hash('sha256', $html))) {
            Flash::set('error', 'The screenplay changed after this preview. Preview the selection again.');
            Http::redirect("/projects/$uuid/pdf");
        }
        $payload = $this->pdf->generate($html, $document['title']);
        session_write_close();
        $name = trim(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $document['title']), '-');
        $name = substr($name ?: 'screenplay', 0, 70);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $name . '.pdf"');
        header('Content-Length: ' . strlen($payload));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $payload;
    }

    public static function options(array $input): array
    {
        $options = [];
        foreach (['scope', 'episode', 'act', 'character', 'plot', 'from', 'through'] as $key) {
            $options[$key] = is_string($input[$key] ?? null) ? substr($input[$key], 0, 100) : '';
        }
        $options['scope'] = array_key_exists($options['scope'], PdfExport::SCOPES) ? $options['scope'] : 'full';
        foreach (['scenes', 'blocks'] as $key) {
            $values = is_array($input[$key] ?? null) ? $input[$key] : [];
            $options[$key] = array_values(array_unique(array_filter($values, static fn ($value) => is_string($value) && preg_match('/^[a-f0-9-]{36}$/D', $value))));
        }
        $options['include_notes'] = ($input['include_notes'] ?? '') === '1';
        $options['filters'] = ManagementController::filters(is_array($input['filters'] ?? null) ? $input['filters'] : $input);
        return $options;
    }
}
