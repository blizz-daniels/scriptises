<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\View;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use RuntimeException;

final class PdfExport
{
    public const SCOPES = [
        'full' => 'Full screenplay',
        'episode' => 'Episode',
        'act' => 'Act',
        'scenes' => 'Selected scenes',
        'character' => 'Character dialogue',
        'plot' => 'Plot thread',
        'range' => 'Scene range',
        'filtered' => 'Search matches',
        'selected' => 'Checked search results',
    ];

    public function __construct(private readonly ScreenplayService $scripts, private readonly ManagementService $management)
    {
    }

    public function outline(int $projectId): array
    {
        return $this->management->rows(
            'SELECT s.id, s.uuid, s.heading, e.title AS episode, a.title AS act, e.uuid AS episode_uuid, a.uuid AS act_uuid
             FROM scenes s LEFT JOIN episodes e ON e.id = s.episode_id LEFT JOIN acts a ON a.id = s.act_id
             WHERE s.project_id = ? ORDER BY s.episode_id IS NULL DESC, e.position, s.act_id IS NULL DESC, a.position, a.id, s.position, s.id',
            [$projectId]
        );
    }

    public function collect(int $user, string $projectUuid, array $options): array
    {
        $project = $this->scripts->project($user, $projectUuid);
        $outline = $this->outline((int) $project['id']);
        $scope = $options['scope'];
        $speaker = null;
        $blockSelection = [];
        $selectedScenes = match ($scope) {
            'full' => array_column($outline, 'uuid'),
            'episode', 'act' => $this->division($outline, $scope, $options[$scope]),
            'scenes' => $options['scenes'],
            'range' => $this->range($outline, $options['from'], $options['through']),
            'plot' => $this->management->plot((int) $project['id'], $options['plot'])['scenes'],
            'character' => $this->dialogueScenes($outline, $options),
            'filtered', 'selected' => [],
            default => throw new RuntimeException('Choose a PDF export scope.'),
        };
        if ($scope === 'character') {
            $speaker = $this->management->character((int) $project['id'], $options['character']);
        }
        if ($scope === 'filtered') {
            $matches = $this->management->search($user, $projectUuid, $options['filters'], true);
            if (isset($matches[0]['block_uuid'])) {
                $blockSelection = array_column($matches, 'block_uuid');
            } else {
                $selectedScenes = array_column($matches, 'scene_uuid');
            }
        } elseif ($scope === 'selected') {
            $selectedScenes = $options['scenes'];
            $blockSelection = $options['blocks'];
        }
        $whole = array_fill_keys($selectedScenes, true);
        $pickedScenes = $this->scenesContainingBlocks((int) $project['id'], $blockSelection);
        $chosen = array_values(array_filter($outline, static fn ($scene) => isset($whole[$scene['uuid']]) || isset($pickedScenes[$scene['uuid']])));
        if (!$chosen) {
            throw new RuntimeException('No scenes or blocks match this export.');
        }

        $conditions = [];
        $bindings = [];
        if ($blockSelection) {
            $selectedIds = array_column(array_filter($chosen, static fn ($scene) => isset($whole[$scene['uuid']])), 'id');
            if ($selectedIds) {
                $conditions[] = 'b.scene_id IN (' . $this->slots($selectedIds) . ')';
                array_push($bindings, ...$selectedIds);
            }
            $conditions[] = 'b.uuid IN (' . $this->slots($blockSelection) . ')';
            array_push($bindings, ...$blockSelection);
        }
        $extra = $conditions ? ' AND (' . implode(' OR ', $conditions) . ')' : '';
        if ($speaker !== null) {
            $extra .= " AND b.block_type = 'dialogue' AND b.speaker_character_id = ?";
            $bindings[] = $speaker['id'];
        }
        if (!$options['include_notes']) {
            $extra .= " AND b.block_type <> 'note'";
        }
        $ids = array_column($chosen, 'id');
        $rows = $this->management->rows(
            'SELECT b.scene_id, b.uuid, b.block_type, b.content, b.metadata, c.name AS speaker_name
             FROM content_blocks b LEFT JOIN characters c ON c.id = b.speaker_character_id
             WHERE b.scene_id IN (' . $this->slots($ids) . ')' . $extra . ' ORDER BY b.scene_id, b.position',
            [...$ids, ...$bindings]
        );
        $blocks = [];
        foreach ($rows as $row) {
            $row['metadata'] = $row['metadata'] !== null ? json_decode($row['metadata'], true) : null;
            $blocks[$row['scene_id']][] = $row;
        }
        $hideEmpty = $speaker !== null || $scope === 'filtered' || $scope === 'selected';
        $scenes = [];
        foreach ($chosen as $scene) {
            $scene['blocks'] = $blocks[$scene['id']] ?? [];
            if ($scene['blocks'] || !$hideEmpty || isset($whole[$scene['uuid']]) && $speaker === null) {
                $scenes[] = $scene;
            }
        }
        if (!$scenes) {
            throw new RuntimeException('This selection contains no printable blocks. Check the filters or include screenplay notes.');
        }
        return [
            'title' => $project['title'],
            'label' => $speaker === null ? self::SCOPES[$scope] : $speaker['name'] . ' · dialogue',
            'selective' => $scope !== 'full',
            'scenes' => $scenes,
        ];
    }

    private function dialogueScenes(array $outline, array $options): array
    {
        if ($options['character'] === '') {
            throw new RuntimeException('Choose the character whose dialogue you want to export.');
        }
        return array_column(array_filter($outline, static function (array $scene) use ($options): bool {
            return ($options['episode'] === '' || $scene['episode_uuid'] === $options['episode'])
                && ($options['act'] === '' || $scene['act_uuid'] === $options['act'])
                && (!$options['scenes'] || in_array($scene['uuid'], $options['scenes'], true));
        }), 'uuid');
    }

    private function division(array $outline, string $kind, string $id): array
    {
        if ($id === '') {
            throw new RuntimeException("Choose an $kind to export.");
        }
        return array_column(array_filter($outline, static fn ($scene) => $scene[$kind . '_uuid'] === $id), 'uuid');
    }

    private function range(array $outline, string $from, string $through): array
    {
        $ids = array_column($outline, 'uuid');
        $start = array_search($from, $ids, true);
        $end = array_search($through, $ids, true);
        if ($start === false || $end === false || $end < $start) {
            throw new RuntimeException('Choose a first scene and a last scene in screenplay order.');
        }
        return array_slice($ids, $start, $end - $start + 1);
    }

    private function scenesContainingBlocks(int $project, array $blocks): array
    {
        if (!$blocks) {
            return [];
        }
        $rows = $this->management->rows(
            'SELECT DISTINCT s.uuid FROM content_blocks b JOIN scenes s ON s.id = b.scene_id
             WHERE s.project_id = ? AND b.uuid IN (' . $this->slots($blocks) . ')',
            [$project, ...$blocks]
        );
        return array_fill_keys(array_column($rows, 'uuid'), true);
    }

    private function slots(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    public function html(array $document): string
    {
        ob_start();
        try {
            View::render('partials/pdf-content', ['document' => $document]);
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    public function generate(string $html, string $title): string
    {
        if (!class_exists(Mpdf::class)) {
            throw new RuntimeException('PDF export needs the Composer dependencies installed.');
        }
        $directory = dirname(__DIR__, 2) . '/storage/pdf-cache';
        if ((!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) || !is_writable($directory)) {
            throw new RuntimeException('The PDF cache directory is not writable.');
        }
        $pdf = new Mpdf([
            'format' => 'Letter', 'margin_left' => 38, 'margin_right' => 25, 'margin_top' => 19, 'margin_bottom' => 20,
            'tempDir' => $directory, 'default_font' => 'dejavusansmono', 'default_font_size' => 11,
            'autoScriptToLang' => true, 'autoLangToFont' => true,
        ]);
        $pdf->SetTitle($title);
        $pdf->SetHTMLFooter('<div style="text-align: right; font-size: 9pt">{PAGENO}.</div>');
        $css = file_get_contents(dirname(__DIR__, 2) . '/public/assets/pdf-layout.css');
        $pdf->WriteHTML($css, HTMLParserMode::HEADER_CSS);
        $chunk = '';
        foreach (explode('<!-- scriptises-pdf-part -->', $html) as $part) {
            if ($chunk !== '' && strlen($chunk) + strlen($part) > 240_000) {
                $pdf->WriteHTML($chunk, HTMLParserMode::HTML_BODY);
                $chunk = '';
            }
            $chunk .= $part;
        }
        if ($chunk !== '') {
            $pdf->WriteHTML($chunk, HTMLParserMode::HTML_BODY);
        }
        return $pdf->OutputBinaryData();
    }
}
