<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Uuid;
use RuntimeException;

final class ProjectDocument
{
    public const VERSION = '1.0.0';
    public const MAX_BYTES = 8_000_000;

    public static function decode(string $source): array
    {
        if ($source === '' || strlen($source) > self::MAX_BYTES) {
            throw new RuntimeException('Choose a JSON backup under 8 MB.');
        }
        try {
            if (str_starts_with($source, "\xEF\xBB\xBF")) {
                $source = substr($source, 3);
            }
            $document = self::unpack(json_decode($source, false, 64, JSON_THROW_ON_ERROR));
        } catch (\JsonException $error) {
            throw new RuntimeException('Invalid JSON: ' . $error->getMessage());
        }
        if (!is_array($document)) {
            throw new RuntimeException('The backup must be a JSON object.');
        }
        return self::validate(self::flatten($document));
    }

    private static function unpack(mixed $value): mixed
    {
        if (is_object($value)) {
            $fields = [];
            foreach (get_object_vars($value) as $key => $item) {
                $fields[$key] = $key === 'metadata' ? $item : self::unpack($item);
            }
            return $fields;
        }
        return is_array($value) ? array_map(self::unpack(...), $value) : $value;
    }

    private static function flatten(array $input): array
    {
        $nested = ($input['schema_version'] ?? null) === '1.0';
        foreach (['episodes', 'acts'] as $kind) {
            foreach (is_array($input[$kind] ?? null) ? $input[$kind] : [] as $row) {
                $nested = $nested || (is_array($row) && (isset($row['scenes']) || isset($row['acts'])));
            }
        }
        if (!$nested) {
            return $input;
        }
        $flattened = $input;
        $flattened['episodes'] = $flattened['acts'] = $flattened['scenes'] = [];
        $appendScenes = static function (mixed $rows, ?string $episode, ?string $act) use (&$flattened): void {
            foreach (self::list($rows, 'scenes') as $scene) {
                if (!is_array($scene)) {
                    throw new RuntimeException('Each scene must be an object.');
                }
                $scene += ['id' => Uuid::v4(), 'episode_id' => $episode, 'act_id' => $act];
                if (is_array($scene['heading'] ?? null)) {
                    $heading = $scene['heading'];
                    $place = self::text($heading['location'] ?? null, 'heading.location', 255);
                    $prefix = self::text($heading['int_ext'] ?? null, 'heading.int_ext', 15);
                    $time = self::text($heading['time_of_day'] ?? null, 'heading.time_of_day', 100);
                    $scene['heading'] = trim("$prefix $place" . ($time ? " - $time" : ''));
                    $scene += ['location' => $place, 'int_ext' => $prefix, 'time_of_day' => $time];
                }
                $scene['blocks'] = array_map(static function ($block) {
                    return is_array($block) ? $block + ['id' => Uuid::v4(), 'speaker_id' => $block['speaker'] ?? null] : $block;
                }, self::list($scene['blocks'] ?? [], 'blocks'));
                $flattened['scenes'][] = $scene;
            }
        };
        $appendActs = static function (mixed $rows, ?string $episode) use (&$flattened, $appendScenes): void {
            foreach (self::list($rows, 'acts') as $act) {
                if (!is_array($act)) {
                    throw new RuntimeException('Each act must be an object.');
                }
                $act += ['id' => Uuid::v4(), 'episode_id' => $episode];
                $flattened['acts'][] = $act;
                $appendScenes($act['scenes'] ?? [], $episode, self::key($act['id'], 'act.id'));
            }
        };
        $appendScenes($input['scenes'] ?? [], null, null);
        $appendActs($input['acts'] ?? [], null);
        foreach (self::list($input['episodes'] ?? [], 'episodes') as $episode) {
            if (!is_array($episode)) {
                throw new RuntimeException('Each episode must be an object.');
            }
            $episode += ['id' => Uuid::v4()];
            $flattened['episodes'][] = $episode;
            $id = self::key($episode['id'], 'episode.id');
            $appendScenes($episode['scenes'] ?? [], $id, null);
            $appendActs($episode['acts'] ?? [], $id);
        }
        $flattened['characters'] = array_map(static function ($character) {
            return is_array($character) ? $character + ['id' => $character['key'] ?? Uuid::v4()] : $character;
        }, self::list($input['characters'] ?? [], 'characters'));
        return $flattened;
    }

    public static function validate(array $input): array
    {
        if (!in_array($input['schema_version'] ?? null, [self::VERSION, '1.0'], true)) {
            throw new RuntimeException('This backup requires schema_version "' . self::VERSION . '".');
        }
        $project = $input['project'] ?? null;
        if (!is_array($project)) {
            throw new RuntimeException('Missing project details.');
        }
        $document = [
            'schema_version' => self::VERSION,
            'project' => [
                'id' => self::key($project['id'] ?? 'project', 'project.id'),
                'title' => self::text($project['title'] ?? null, 'project.title', 255, true),
                'type' => self::choice($project['type'] ?? 'film', ['film', 'short_film', 'limited_series', 'tv_series', 'other'], 'project.type'),
                'status' => self::choice($project['status'] ?? 'active', ['active', 'archived'], 'project.status'),
                'description' => self::text($project['description'] ?? null, 'project.description', 65535, false, true),
            ],
        ];
        $lookup = [];
        $positions = [];
        $names = [];
        $blockKeys = [];
        foreach (['characters', 'episodes', 'acts', 'scenes', 'plot_threads'] as $kind) {
            $document[$kind] = [];
            $lookup[$kind] = [];
            foreach (self::list($input[$kind] ?? [], $kind) as $index => $row) {
                $path = $kind . '[' . ($index + 1) . ']';
                if (!is_array($row)) {
                    throw new RuntimeException("$path must be an object.");
                }
                $id = self::key($row['id'] ?? null, "$path.id");
                if (isset($lookup[$kind][$id])) {
                    throw new RuntimeException("$path has a duplicate ID.");
                }
                $item = ['id' => $id];
                if ($kind === 'characters') {
                    $item['name'] = self::text($row['name'] ?? null, "$path.name", 255, true);
                    $lower = mb_strtolower(trim($item['name']));
                    if (isset($names[$lower])) {
                        throw new RuntimeException("$path has a duplicate character name.");
                    }
                    $names[$lower] = true;
                    $item['aliases'] = array_values(array_unique(array_map(
                        fn ($alias) => self::text($alias, "$path.aliases", 255, true),
                        self::list($row['aliases'] ?? [], "$path.aliases")
                    )));
                }
                if (in_array($kind, ['episodes', 'acts', 'plot_threads'], true)) {
                    $item['title'] = self::text($row['title'] ?? null, "$path.title", 255, true);
                }
                if (in_array($kind, ['characters', 'plot_threads'], true)) {
                    $item['description'] = self::text($row['description'] ?? null, "$path.description", 65535, false, true);
                }
                if (in_array($kind, ['episodes', 'acts', 'scenes'], true)) {
                    $item['position'] = self::position($row['position'] ?? $index + 1, "$path.position");
                }
                if ($kind === 'episodes') {
                    if (isset($positions[$item['position']])) {
                        throw new RuntimeException("$path has a duplicate episode position.");
                    }
                    $positions[$item['position']] = true;
                }
                if (in_array($kind, ['acts', 'scenes'], true)) {
                    $item['episode_id'] = self::reference($row['episode_id'] ?? null, $lookup['episodes'], "$path.episode_id");
                }
                if ($kind === 'scenes') {
                    $item['act_id'] = self::reference($row['act_id'] ?? null, $lookup['acts'], "$path.act_id");
                    if ($item['act_id'] !== null && ($parent = $lookup['acts'][$item['act_id']]['episode_id']) !== null && $parent !== $item['episode_id']) {
                        throw new RuntimeException("$path must belong to the selected act's episode.");
                    }
                    $item['heading'] = self::text($row['heading'] ?? null, "$path.heading", 500);
                    $item['int_ext'] = ($row['int_ext'] ?? null) === null ? null : self::choice($row['int_ext'], ['INT.', 'EXT.', 'INT./EXT.', 'OTHER'], "$path.int_ext");
                    $item['location'] = self::text($row['location'] ?? null, "$path.location", 255);
                    $item['time_of_day'] = self::text($row['time_of_day'] ?? null, "$path.time_of_day", 100);
                    $item['summary'] = self::text($row['summary'] ?? null, "$path.summary", 65535, false, true);
                    $item['blocks'] = self::blocks($row['blocks'] ?? [], $lookup['characters'], $blockKeys, $path);
                }
                if ($kind === 'plot_threads') {
                    foreach (ManagementService::PLOT_LINKS as $type) {
                        $item[$type] = array_values(array_unique(array_map(
                            fn ($ref) => self::reference($ref, $lookup[$type], "$path.$type", true),
                            self::list($row[$type] ?? [], "$path.$type")
                        )));
                    }
                }
                $lookup[$kind][$id] = $item;
                $document[$kind][] = $item;
            }
        }

        $document['scene_character_links'] = [];
        $pairs = [];
        foreach (self::list($input['scene_character_links'] ?? [], 'scene_character_links') as $link) {
            if (!is_array($link)) {
                throw new RuntimeException('Each scene-character link must be an object.');
            }
            $scene = self::reference($link['scene_id'] ?? null, $lookup['scenes'], 'scene_character_links.scene_id', true);
            $character = self::reference($link['character_id'] ?? null, $lookup['characters'], 'scene_character_links.character_id', true);
            if (isset($pairs[$scene][$character])) {
                throw new RuntimeException('Duplicate scene-character link.');
            }
            $pairs[$scene][$character] = true;
            $document['scene_character_links'][] = [
                'scene_id' => $scene,
                'character_id' => $character,
                'association_type' => self::choice($link['association_type'] ?? 'associated', ['associated', 'present'], 'scene_character_links.association_type'),
                'notes' => self::text($link['notes'] ?? null, 'scene_character_links.notes', 500),
            ];
        }
        return $document;
    }

    private static function blocks(mixed $items, array $characters, array &$keys, string $scenePath): array
    {
        $blocks = [];
        $positions = [];
        foreach (self::list($items, "$scenePath.blocks") as $index => $row) {
            $path = $scenePath . '.blocks[' . ($index + 1) . ']';
            if (!is_array($row)) {
                throw new RuntimeException("$path must be an object.");
            }
            $id = self::key($row['id'] ?? null, "$path.id");
            $position = self::position($row['position'] ?? $index + 1, "$path.position");
            if (isset($keys[$id]) || isset($positions[$position])) {
                throw new RuntimeException("$path has a duplicate block ID or position.");
            }
            $keys[$id] = $positions[$position] = true;
            $blocks[] = [
                'id' => $id,
                'position' => $position,
                'type' => self::choice($row['type'] ?? null, ManagementService::BLOCK_TYPES, "$path.type"),
                'text' => self::text($row['text'] ?? '', "$path.text", 200000) ?? '',
                'speaker_id' => self::reference($row['speaker_id'] ?? null, $characters, "$path.speaker_id"),
                'metadata' => $row['metadata'] ?? null,
            ];
        }
        return $blocks;
    }

    private static function list(mixed $value, string $path): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException("$path must be a list.");
        }
        return $value;
    }

    private static function key(mixed $value, string $path): string
    {
        if (!is_string($value) || !preg_match('/^[a-zA-Z0-9._-]{1,100}$/D', $value)) {
            throw new RuntimeException("$path must be a nonempty ID using letters, numbers, periods, underscores or hyphens.");
        }
        return $value;
    }

    private static function reference(mixed $value, array $items, string $path, bool $required = false): ?string
    {
        if ($value === null && !$required) {
            return null;
        }
        $id = self::key($value, $path);
        if (!isset($items[$id])) {
            throw new RuntimeException("$path refers to an item that is missing.");
        }
        return $id;
    }

    private static function position(mixed $value, string $path): int
    {
        if (!is_int($value) || $value < 1 || $value > 4294967295) {
            throw new RuntimeException("$path must be a positive integer supported by the database.");
        }
        return $value;
    }

    private static function choice(mixed $value, array $options, string $path): string
    {
        if (!in_array($value, $options, true)) {
            throw new RuntimeException("$path has an unsupported value.");
        }
        return $value;
    }

    private static function text(mixed $value, string $path, int $limit, bool $required = false, bool $byteLimit = false): ?string
    {
        if ($value === null && !$required) {
            return null;
        }
        if (!is_string($value) || ($required && trim($value) === '') || ($byteLimit ? strlen($value) : mb_strlen($value)) > $limit) {
            throw new RuntimeException("$path must be " . ($required ? 'nonempty ' : '') . "text within its $limit " . ($byteLimit ? 'byte' : 'character') . ' limit.');
        }
        return $value;
    }
}
