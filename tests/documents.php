<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\PdfController;
use App\Services\DocxReader;
use App\Services\FountainParser;
use App\Services\ManagementService;
use App\Services\PasteParser;
use App\Services\PdfExport;
use App\Services\ProjectTransfer;
use App\Services\ScreenplayService;
use App\Support\Uuid;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Document test failed: ' . $message);
    }
}

function pdfText(string $binary, string $converter): string
{
    if ($converter === '') {
        return '';
    }
    $file = tempnam(sys_get_temp_dir(), 'scriptises-test-pdf-');
    try {
        file_put_contents($file, $binary);
        $process = proc_open([$converter, '-enc', 'UTF-8', '-layout', $file, '-'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        verify(is_resource($process), 'pdftotext started');
        $text = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        verify(proc_close($process) === 0 && is_string($text), 'PDF text is extractable');
        return preg_replace('/\s+/u', ' ', $text) ?? '';
    } finally {
        unlink($file);
    }
}

$converter = (string) getenv('PDFTOTEXT_BIN');
$pdo = $database->pdo();
$pdo->prepare('INSERT INTO users (uuid, email, password_hash) VALUES (?, ?, ?)')->execute([
    Uuid::v4(), 'documents-' . bin2hex(random_bytes(8)) . '@example.test', password_hash('only-a-test-password', PASSWORD_DEFAULT),
]);
$user = (int) $pdo->lastInsertId();
$scripts = new ScreenplayService($database);
$management = new ManagementService($database, $scripts);
$transfer = new ProjectTransfer($database, $scripts, $management);
$pdf = new PdfExport($scripts, $management);
$docx = tempnam(sys_get_temp_dir(), 'scriptises-test-word-');
$fountain = <<<'FOUNTAIN'
Title: The Quiet Desk
Credit: Written by
Author: Example Writer

# Act One

INT. LIBRARY - NIGHT #1A#

= A letter interrupts the evening.

The lamp throws a warm circle.

@Mara (V.O.)
(whispering)
The letter arrived today.
Read the address.

BRICK ^
This line belongs to Brick.

[[Recheck the opening beat.]]
/* An unpublished alternate.
Keep this in the original archive. */

> DISSOLVE TO:

.ROOFTOP

!WIND HOWLS
The city glows.

>THE END<
FOUNTAIN;

try {
    Settings::setOutputEscapingEnabled(true);
    $word = new PhpWord();
    $word->addParagraphStyle('SceneHeading', ['keepNext' => true]);
    $word->addParagraphStyle('Character', ['indentation' => ['left' => 500]]);
    $word->addParagraphStyle('Dialogue', ['indentation' => ['left' => 240]]);
    $body = $word->addSection();
    $body->addText('The Archive', [], 'SceneHeading');
    $body->addText('Sofia', [], 'Character');
    $speech = $body->addTextRun('Dialogue');
    $speech->addText('All punctuation ', ['bold' => true]);
    $speech->addText('stays intact & readable.');
    $cell = $body->addTable()->addRow()->addCell();
    $cell->addText('The contents of the table follow the dialogue.');
    IOFactory::createWriter($word, 'Word2007')->save($docx);
    $read = DocxReader::read($docx);
    verify(str_contains($read['text'], 'The contents of the table'), 'DOCX text includes body table paragraphs');
    $wordDraft = PasteParser::parse('Word Test', $read['text'], $read['hints'])['document'];
    verify($wordDraft['scenes'][0]['heading'] === 'The Archive', 'Word paragraph style identifies an irregular heading');
    verify($wordDraft['scenes'][0]['blocks'][1]['text'] === 'All punctuation stays intact & readable.', 'Word runs preserve text order');
    verify($wordDraft['scenes'][0]['blocks'][1]['speaker_id'] === $wordDraft['characters'][0]['id'], 'style-based Word speaker association');
    $wordBytes = file_get_contents($docx);
    $wordProject = $transfer->restore($user, $wordDraft, $wordBytes, 'docx');
    $wordId = $scripts->project($user, $wordProject)['id'];
    $wordSource = $management->rows('SELECT source_type, original_source FROM import_records WHERE project_id = ?', [$wordId])[0];
    verify($wordSource['source_type'] === 'docx' && hash_equals($wordBytes, $wordSource['original_source']), 'original binary Word source round trips');
    try {
        DocxReader::read(__FILE__);
        throw new LogicException('An invalid Word file should be rejected.');
    } catch (RuntimeException $error) {
        verify(str_contains($error->getMessage(), 'readable'), 'invalid DOCX rejected cleanly');
    }

    $parsed = FountainParser::parse($fountain, 'Fallback');
    $draft = $parsed['document'];
    verify($draft['project']['title'] === 'The Quiet Desk' && count($draft['scenes']) === 2, 'Fountain title and regular/forced scenes');
    verify($draft['scenes'][0]['summary'] === 'A letter interrupts the evening.', 'Fountain scene synopsis');
    verify(array_column($draft['characters'], 'name') === ['Mara', 'BRICK'], 'Fountain forced cue and dual dialogue speakers');
    verify(array_filter($draft['scenes'][0]['blocks'], static fn ($block) => ($block['metadata']['fountain'] ?? '') === 'boneyard') !== [], 'Fountain boneyard preserved as a note');
    verify($draft['scenes'][1]['blocks'][0]['type'] === 'action' && $draft['scenes'][1]['blocks'][0]['text'] === 'WIND HOWLS', 'forced action and inline punctuation');
    verify(count($parsed['flags']) >= 1, 'dual dialogue interpretation flagged');
    $project = $transfer->restore($user, $draft, $fountain, 'fountain');
    $saved = $transfer->backup($user, $project);
    $library = $saved['scenes'][0];
    $rooftop = $saved['scenes'][1];
    $mara = $saved['characters'][0]['id'];
    $act = $saved['acts'][0]['id'];
    $letter = array_values(array_filter($library['blocks'], static fn ($block) => $block['text'] === 'The letter arrived today.'))[0];
    $plot = $management->savePlot($user, $project, '', 'The ending', '', ['scenes' => [$rooftop['id']]]);
    $episode = $scripts->createEpisode($user, $project, 'Pilot');
    $extra = $scripts->createScene($user, $project, 'EXT. TRAIN STATION - MORNING', $episode['uuid'], null);
    $scripts->createBlock($user, $project, $extra['uuid'], 'action', 'A sealed envelope lies alone.');

    $cases = [
        'full' => [['scope' => 'full'], ['The letter arrived today.', 'WIND HOWLS', 'A sealed envelope lies alone.'], ['Recheck the opening beat.']],
        'episode' => [['scope' => 'episode', 'episode' => $episode['uuid']], ['A sealed envelope lies alone.'], ['WIND HOWLS']],
        'act' => [['scope' => 'act', 'act' => $act], ['WIND HOWLS'], ['A sealed envelope lies alone.']],
        'scene' => [['scope' => 'scenes', 'scenes' => [$rooftop['id']]], ['WIND HOWLS'], ['This line belongs to Brick.']],
        'multiscene' => [['scope' => 'scenes', 'scenes' => [$extra['uuid'], $library['id']]], ['The letter arrived today.', 'A sealed envelope lies alone.'], ['WIND HOWLS']],
        'range' => [['scope' => 'range', 'from' => $rooftop['id'], 'through' => $extra['uuid']], ['WIND HOWLS', 'A sealed envelope lies alone.'], ['This line belongs to Brick.']],
        'character' => [['scope' => 'character', 'character' => $mara, 'act' => $act, 'scenes' => [$library['id']]], ['MARA', 'INT. LIBRARY - NIGHT', 'The letter arrived today.'], ['This line belongs to Brick.', 'The lamp throws a warm circle.', 'WIND HOWLS']],
        'plot' => [['scope' => 'plot', 'plot' => $plot], ['WIND HOWLS'], ['The letter arrived today.', 'A sealed envelope lies alone.']],
        'search' => [['scope' => 'filtered', 'type' => 'dialogue', 'q' => 'belongs to Brick'], ['BRICK', 'This line belongs to Brick.'], ['The letter arrived today.', 'WIND HOWLS']],
        'checked' => [['scope' => 'selected', 'blocks' => [$letter['id']]], ['MARA', 'The letter arrived today.'], ['Read the address.', 'This line belongs to Brick.', 'WIND HOWLS']],
        'notes' => [['scope' => 'scenes', 'scenes' => [$library['id']], 'include_notes' => '1'], ['Recheck the opening beat.'], ['WIND HOWLS']],
    ];
    foreach ($cases as $name => [$options, $include, $exclude]) {
        $document = $pdf->collect($user, $project, PdfController::options($options));
        $html = $pdf->html($document);
        $file = $pdf->generate($html, $document['title']);
        verify(str_starts_with($file, '%PDF-'), "$name generates a PDF");
        $text = $converter !== '' ? pdfText($file, $converter) : strip_tags($html);
        foreach ($include as $item) {
            verify(str_contains($text, $item), "$name includes '$item'");
        }
        foreach ($exclude as $item) {
            verify(!str_contains($text, $item), "$name excludes '$item'");
        }
    }
    echo 'DOCX, Fountain, and 11 PDF export checks passed' . ($converter ? ' (extracted PDF text verified).' : ' (preview text verified; set PDFTOTEXT_BIN to check PDF text).') . "\n";
} finally {
    if (is_file($docx)) {
        unlink($docx);
    }
    foreach ($scripts->projectsForUser($user, true) as $row) {
        $scripts->deleteProject($user, $row['uuid'], $row['title']);
    }
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$user]);
}
