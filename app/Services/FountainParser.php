<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Uuid;
use RuntimeException;

final class FountainParser
{
    private const TITLE_FIELDS = '/^(Title|Credit|Authors?|Source|Draft Date|Contact|Copyright|Notes|Revision):\s*(.*)$/iu';
    private const HEADING = '/^(INT\.?\/EXT\.?|EXT\.?\/INT\.?|I\/E\.?|INT\.?|EXT\.?|EST\.?)\s+(.+)$/iu';

    public static function parse(string $source, string $fallbackTitle): array
    {
        if (strlen($source) > 2_000_000 || !mb_check_encoding($source, 'UTF-8')) {
            throw new RuntimeException('Choose a UTF-8 Fountain screenplay under 2 MB.');
        }
        $source = str_starts_with($source, "\xEF\xBB\xBF") ? substr($source, 3) : $source;
        $lines = preg_split('/\R/u', $source);
        if (count($lines) > 20_000) {
            throw new RuntimeException('The Fountain file contains too many lines to review in one import.');
        }

        $titlePage = self::titlePage($lines);
        $draft = [
            'schema_version' => ProjectDocument::VERSION,
            'project' => ['title' => mb_substr(trim($titlePage['title'] ?? '') ?: $fallbackTitle, 0, 255)],
            'characters' => [], 'acts' => [], 'scenes' => [],
        ];
        $flags = [];
        $names = [];
        $scene = $speaker = $act = null;
        $waitingNotes = [];
        foreach ($titlePage as $key => $value) {
            $waitingNotes[] = self::block('note', ucfirst($key) . ': ' . $value, null, ['fountain' => 'title_page']);
        }
        $blank = true;

        for ($index = 0, $count = count($lines); $index < $count; $index++) {
            $raw = $lines[$index];
            $line = trim($raw);
            $next = trim($lines[$index + 1] ?? '');
            if ($line === '') {
                if ($speaker !== null && $raw === '  ') {
                    $draft['scenes'][$scene]['blocks'][] = self::block('dialogue', '', $speaker);
                    continue;
                }
                $speaker = null;
                $blank = true;
                continue;
            }
            if (preg_match('/^(\[\[|\/\*)/', $line, $note)) {
                $end = $note[1] === '[[' ? ']]' : '*/';
                $text = substr($line, 2);
                while (!str_contains($text, $end) && $index + 1 < $count) {
                    $text .= "\n" . $lines[++$index];
                }
                [$text, $tail] = array_pad(explode($end, $text, 2), 2, '');
                $entry = self::block('note', $text, null, ['fountain' => $end === '*/' ? 'boneyard' : 'note']);
                if ($scene === null) {
                    $waitingNotes[] = $entry;
                } else {
                    $draft['scenes'][$scene]['blocks'][] = $entry;
                }
                if (trim($tail) === '') {
                    continue;
                }
                $line = trim($raw = $tail);
            }
            if (preg_match('/^(#+)\s*(\S.*)$/u', $line, $section)) {
                if (strlen($section[1]) === 1 && preg_match('/^Act\b/iu', $section[2]) && mb_strlen($section[2]) <= 255) {
                    $act = Uuid::v4();
                    $draft['acts'][] = ['id' => $act, 'title' => $section[2]];
                    $scene = $speaker = null;
                } else {
                    $waitingNotes[] = self::block('note', $section[2], null, ['fountain' => 'section', 'level' => strlen($section[1])]);
                }
                $blank = true;
                continue;
            }
            $forcedHeading = preg_match('/^\.([\p{L}\p{N}].*)$/u', $line, $forced);
            $isHeading = $forcedHeading || ($blank && $next === '' && preg_match(self::HEADING, $line));
            if ($isHeading) {
                $heading = $forcedHeading ? $forced[1] : $line;
                $number = null;
                if (preg_match('/\s+#([A-Za-z0-9.-]+)#$/D', $heading, $numbered)) {
                    $number = $numbered[1];
                    $heading = substr($heading, 0, -strlen($numbered[0]));
                }
                $scene = self::scene($draft, $heading, $act, $waitingNotes);
                if ($number !== null) {
                    $draft['scenes'][$scene]['blocks'][] = self::block('note', 'Original scene number: ' . $number, null, ['fountain' => 'scene_number', 'number' => $number]);
                }
                $speaker = null;
                $blank = false;
                continue;
            }
            if ($scene === null) {
                $scene = self::scene($draft, 'UNTITLED SCENE', $act, $waitingNotes);
                $flags[$draft['scenes'][$scene]['id']] = 'Text appears before a scene heading. Review where it belongs.';
            } elseif ($waitingNotes) {
                array_push($draft['scenes'][$scene]['blocks'], ...$waitingNotes);
                $waitingNotes = [];
            }
            $metadata = null;
            $type = 'action';
            $text = $raw;
            $previousSpeaker = $speaker;
            if (str_starts_with($line, '!')) {
                $speaker = null;
                $text = substr(ltrim($raw), 1);
            } elseif (preg_match('/^={3,}$/D', $line)) {
                $speaker = null;
                $type = 'note';
                $text = 'Page break';
                $metadata = ['fountain' => 'page_break'];
            } elseif (str_starts_with($line, '=')) {
                $speaker = null;
                $synopsis = ltrim(substr($line, 1));
                $current = &$draft['scenes'][$scene];
                $current['summary'] = $current['summary'] === null ? $synopsis : $current['summary'] . "\n" . $synopsis;
                unset($current);
                $blank = false;
                continue;
            } elseif (preg_match('/^>\s*(.*?)\s*<$/u', $line, $centered)) {
                $speaker = null;
                $text = $centered[1];
                $metadata = ['fountain' => 'centered'];
            } elseif (str_starts_with($line, '>') || ($blank && $next === '' && preg_match('/^\p{Lu}[\p{Lu}\d ._-]* TO:$/u', $line) && rtrim($raw) === $raw)) {
                $speaker = null;
                $type = 'transition';
                $text = ltrim($line, '> ');
            } elseif (str_starts_with($line, '~')) {
                $text = substr($line, 1);
                $type = $speaker === null ? 'action' : 'dialogue';
                $metadata = ['fountain' => 'lyric'];
            } elseif (self::isCue($line, $blank, $next)) {
                $type = 'character';
                $text = ltrim($line, '@');
                if (str_ends_with($text, '^')) {
                    $text = rtrim(substr($text, 0, -1));
                    $metadata = ['fountain' => 'dual_dialogue'];
                }
                $name = trim(preg_replace('/(?:\s*\([^()]+\))+\s*$/u', '', $text) ?: $text);
                $name = mb_substr($name, 0, 255);
                $key = mb_strtolower($name);
                if (!isset($names[$key])) {
                    $names[$key] = Uuid::v4();
                    $draft['characters'][] = ['id' => $names[$key], 'name' => $name];
                }
                $speaker = $names[$key];
            } elseif ($speaker !== null) {
                $type = str_starts_with($line, '(') && str_ends_with($line, ')') ? 'parenthetical' : 'dialogue';
                $text = $line;
            }
            $entry = self::block($type, $text, $type === 'dialogue' ? $speaker : null, $metadata);
            if (($metadata['fountain'] ?? null) === 'dual_dialogue') {
                $flags[$entry['id']] = 'Simultaneous dialogue is kept in reading order.';
            } elseif ($type === 'character' && !str_starts_with($line, '@') && $previousSpeaker === null) {
                $flags[$entry['id']] = 'Uppercase Fountain cue. Review its speaker and following dialogue.';
            }
            $draft['scenes'][$scene]['blocks'][] = $entry;
            $blank = false;
        }

        if (!$draft['scenes']) {
            throw new RuntimeException('The Fountain file does not contain screenplay scenes or text.');
        }
        if ($waitingNotes) {
            array_push($draft['scenes'][array_key_last($draft['scenes'])]['blocks'], ...$waitingNotes);
        }
        return ['document' => ProjectDocument::validate($draft), 'flags' => $flags];
    }

    private static function titlePage(array &$lines): array
    {
        $fields = [];
        $current = null;
        $consumed = 0;
        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                if (!$fields) {
                    continue;
                }
                break;
            }
            if (preg_match(self::TITLE_FIELDS, $line, $match)) {
                $current = strtolower($match[1]);
                $fields[$current] = $match[2];
            } elseif ($current !== null && preg_match('/^( {3,}|\t)\S/u', $line)) {
                $fields[$current] = ltrim($fields[$current] . "\n" . trim($line));
            } else {
                break;
            }
            $consumed = $index + 1;
        }
        $lines = array_slice($lines, $consumed);
        return $fields;
    }

    private static function scene(array &$draft, string $heading, ?string $act, array &$notes): int
    {
        $scene = ['id' => Uuid::v4(), 'act_id' => $act, 'heading' => $heading, 'summary' => null, 'blocks' => $notes];
        if (preg_match(self::HEADING, $heading, $parts)) {
            $prefix = strtoupper(str_replace('.', '', $parts[1]));
            $setting = preg_split('/\s+[–—-]\s+/u', $parts[2], 2);
            $scene['int_ext'] = match ($prefix) {
                'INT' => 'INT.', 'EXT' => 'EXT.', 'EST' => 'OTHER', default => 'INT./EXT.',
            };
            $scene['location'] = mb_strlen($setting[0]) <= 255 ? $setting[0] : null;
            $scene['time_of_day'] = isset($setting[1]) && mb_strlen($setting[1]) <= 100 ? $setting[1] : null;
        }
        $draft['scenes'][] = $scene;
        $notes = [];
        return array_key_last($draft['scenes']);
    }

    private static function isCue(string $line, bool $blank, string $next): bool
    {
        if (str_starts_with($line, '@')) {
            return trim(substr($line, 1), ' ^') !== '';
        }
        $name = preg_replace('/(?:\s*\([^()]+\))*\s*\^?$/u', '', $line);
        return $blank && $next !== '' && mb_strlen($line) <= 255 && preg_match('/\p{Lu}/u', $name)
            && mb_strtoupper($name) === $name && !preg_match('/[.!?;:]$/u', $name);
    }

    private static function block(string $type, string $text, ?string $speaker, ?array $metadata = null): array
    {
        return ['id' => Uuid::v4(), 'type' => $type, 'text' => $text, 'speaker_id' => $speaker, 'metadata' => $metadata];
    }
}
