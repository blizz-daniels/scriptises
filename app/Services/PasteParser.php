<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Uuid;
use RuntimeException;

final class PasteParser
{
    public static function parse(string $title, string $source, array $hints = []): array
    {
        if (trim($source) === '' || strlen($source) > 2_000_000 || !mb_check_encoding($source, 'UTF-8')) {
            throw new RuntimeException('Paste nonempty UTF-8 screenplay text under 2 MB.');
        }
        $draft = [
            'schema_version' => ProjectDocument::VERSION,
            'project' => ['title' => $title],
            'characters' => [], 'episodes' => [], 'acts' => [], 'scenes' => [],
        ];
        $scene = null;
        $episode = $act = $speaker = null;
        $characters = [];
        $flags = [];
        $lines = preg_split('/\R/u', $source);
        foreach ($lines as $index => $raw) {
            $line = trim($raw);
            $hint = $hints[$index]['type'] ?? null;
            if ($line === '') {
                if (!in_array($hints[$index + 1]['type'] ?? null, ['dialogue', 'parenthetical'], true)) {
                    $speaker = null;
                }
                continue;
            }
            if ($hint === null && preg_match('/^Episode\s+\d+\b/iu', $line) && mb_strlen($line) <= 255) {
                $episode = Uuid::v4();
                $draft['episodes'][] = ['id' => $episode, 'title' => $line];
                $scene = $act = $speaker = null;
                continue;
            }
            if ($hint === null && preg_match('/^(Act\s+[\dIVXLCDM]+)\b(?:\s*\((.+)\))?/iu', $line, $parts) && mb_strlen($line) <= 255) {
                $act = Uuid::v4();
                $draft['acts'][] = ['id' => $act, 'title' => isset($parts[2]) ? $parts[1] : $line, 'episode_id' => $episode];
                $scene = $speaker = null;
                if (isset($parts[2])) {
                    $scene = self::addScene($draft, $parts[2], $episode, $act);
                    $flags[$draft['scenes'][$scene]['id']] = 'Scene inferred from the act heading.';
                }
                continue;
            }
            $standardHeading = preg_match('/^(INT\.\/EXT\.|EXT\.\/INT\.|INT\/EXT\.|I\/E\.|INT\.|EXT\.)\s+(.+)$/iu', $line, $heading);
            if (mb_strlen($line) <= 500 && ($hint === 'heading' || ($hint === null && ($standardHeading || preg_match('/^Scene\s+\d+\b/iu', $line))))) {
                $scene = self::addScene($draft, $line, $episode, $act);
                $speaker = null;
                if ($standardHeading) {
                    $slug = strtoupper($heading[1]);
                    $draft['scenes'][$scene]['int_ext'] = in_array($slug, ['INT.', 'EXT.'], true) ? $slug : 'INT./EXT.';
                    $parts = preg_split('/\s+[–—-]\s+/u', $heading[2], 2);
                    $draft['scenes'][$scene]['location'] = mb_strlen($parts[0]) <= 255 ? $parts[0] : null;
                    $draft['scenes'][$scene]['time_of_day'] = isset($parts[1]) && mb_strlen($parts[1]) <= 100 ? $parts[1] : null;
                } elseif ($hint === null) {
                    $flags[$draft['scenes'][$scene]['id']] = 'Check this inferred scene heading.';
                }
                continue;
            }
            if ($scene === null) {
                $scene = self::addScene($draft, 'UNTITLED SCENE', $episode, $act);
                $flags[$draft['scenes'][$scene]['id']] = 'No explicit heading was found. Rename or combine this scene.';
            }
            $type = 'action';
            $id = Uuid::v4();
            $next = trim($lines[$index + 1] ?? '');
            $cue = $hint === 'character' || ($hint === null && $speaker === null && $next !== '' && mb_strlen($line) <= 44
                && preg_match("/^[\p{Lu}][\p{Lu}\d .’'()_-]+$/u", $line) && !preg_match('/^[\d\W]+$/u', $line));
            if ($hint === 'transition' || ($hint === null && (preg_match('/^(?:FADE (?:IN|OUT)|CUT TO|DISSOLVE TO|SMASH CUT TO)[:.]?$/iu', $line) || preg_match('/^[A-Z ]+ TO:$/', $line)))) {
                $type = 'transition';
                $speaker = null;
            } elseif ($cue) {
                $type = 'character';
                $name = trim(preg_replace('/\s*\([^()]+\)\s*$/u', '', $line) ?? $line);
                $name = mb_substr($name !== '' ? $name : $line, 0, 255);
                $key = mb_strtolower($name);
                if (!isset($characters[$key])) {
                    $characters[$key] = Uuid::v4();
                    $draft['characters'][] = ['id' => $characters[$key], 'name' => $name];
                }
                $speaker = $characters[$key];
                if ($hint === null) {
                    $flags[$id] = 'Uppercase text inferred to be a character cue. Check the following dialogue.';
                }
            } elseif ($hint !== null && $hint !== 'heading') {
                $type = $hint;
                if ($type === 'dialogue' && $speaker === null) {
                    $flags[$id] = 'Word marks this paragraph as dialogue. Assign its speaker.';
                }
                if ($type === 'action') {
                    $speaker = null;
                }
            } elseif ($speaker !== null) {
                $type = str_starts_with($line, '(') && str_ends_with($line, ')') ? 'parenthetical' : 'dialogue';
            }
            $draft['scenes'][$scene]['blocks'][] = [
                'id' => $id,
                'type' => $type,
                'text' => $raw,
                'speaker_id' => $type === 'dialogue' ? $speaker : null,
                'metadata' => isset($hints[$index]['style']) ? ['docx_style' => $hints[$index]['style']] : null,
            ];
        }
        if (!$draft['scenes']) {
            throw new RuntimeException('No scene text was found. Include at least a scene heading or some screenplay content.');
        }
        return ['document' => ProjectDocument::validate($draft), 'flags' => $flags];
    }

    private static function addScene(array &$draft, string $heading, ?string $episode, ?string $act): int
    {
        $draft['scenes'][] = ['id' => Uuid::v4(), 'heading' => $heading, 'episode_id' => $episode, 'act_id' => $act, 'blocks' => []];
        return count($draft['scenes']) - 1;
    }
}
