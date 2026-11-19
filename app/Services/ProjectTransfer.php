<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Uuid;
use RuntimeException;

final class ProjectTransfer
{
    public function __construct(
        private readonly Database $db,
        private readonly ScreenplayService $scripts,
        private readonly ManagementService $management
    ) {}

    public function backup(int $user, string $uuid, ?array $selection = null): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $document = $this->snapshot($user, $uuid);
            $pdo->commit();
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        return $selection === null ? $document : $this->select($document, $selection);
    }

    private function snapshot(int $user, string $uuid): array
    {
        $project = $this->scripts->project($user, $uuid);
        $params = [$project['id']];
        $rows = $this->management->rows(...);
        $document = [
            'schema_version' => ProjectDocument::VERSION,
            'project' => [
                'id' => $project['uuid'],
                'title' => $project['title'],
                'type' => $project['project_type'],
                'description' => $project['description'],
                'status' => $project['status'],
            ],
        ];
        $aliases = [];
        foreach ($rows('SELECT a.alias, c.uuid FROM character_aliases a JOIN characters c ON c.id = a.character_id WHERE c.project_id = ? ORDER BY a.id', $params) as $alias) {
            $aliases[$alias['uuid']][] = $alias['alias'];
        }
        $document['characters'] = $rows('SELECT uuid AS id, name, description FROM characters WHERE project_id = ? ORDER BY characters.id', $params);
        foreach ($document['characters'] as &$character) {
            $character['aliases'] = $aliases[$character['id']] ?? [];
        }
        unset($character);
        $document['episodes'] = $rows('SELECT uuid AS id, title, position FROM episodes WHERE project_id = ? ORDER BY position', $params);
        $document['acts'] = $rows(
            'SELECT a.uuid AS id, a.title, a.position, e.uuid AS episode_id FROM acts a LEFT JOIN episodes e ON e.id = a.episode_id WHERE a.project_id = ? ORDER BY a.episode_id IS NULL DESC, e.position, a.position, a.id',
            $params
        );
        $document['scenes'] = $rows(
            'SELECT s.uuid AS id, s.position, s.heading, s.int_ext, s.location, s.time_of_day, s.summary, e.uuid AS episode_id, a.uuid AS act_id
             FROM scenes s LEFT JOIN episodes e ON e.id = s.episode_id LEFT JOIN acts a ON a.id = s.act_id WHERE s.project_id = ?
             ORDER BY s.episode_id IS NULL DESC, e.position, s.act_id IS NULL DESC, a.position, s.position, s.id',
            $params
        );
        $blocks = [];
        foreach ($rows(
            'SELECT b.uuid AS id, b.position, b.block_type AS type, b.content AS text, c.uuid AS speaker_id, b.metadata, s.uuid AS scene
             FROM content_blocks b JOIN scenes s ON s.id = b.scene_id LEFT JOIN characters c ON c.id = b.speaker_character_id
             WHERE s.project_id = ? ORDER BY b.scene_id, b.position',
            $params
        ) as $block) {
            $scene = $block['scene'];
            unset($block['scene']);
            $block['metadata'] = $block['metadata'] === null ? null : json_decode($block['metadata'], false, 64, JSON_THROW_ON_ERROR);
            $blocks[$scene][] = $block;
        }
        foreach ($document['scenes'] as &$scene) {
            $scene['blocks'] = $blocks[$scene['id']] ?? [];
        }
        unset($scene);
        $document['scene_character_links'] = $rows(
            'SELECT s.uuid AS scene_id, c.uuid AS character_id, sc.association_type, sc.notes
             FROM scene_characters sc JOIN scenes s ON s.id = sc.scene_id JOIN characters c ON c.id = sc.character_id WHERE s.project_id = ?',
            $params
        );
        $document['plot_threads'] = [];
        foreach ($this->management->plots((int) $project['id']) as $row) {
            $plot = $this->management->plot((int) $project['id'], $row['uuid']);
            $document['plot_threads'][] = ['id' => $row['uuid']] + array_intersect_key($plot, array_flip(['title', 'description', ...ManagementService::PLOT_LINKS]));
        }
        return $document;
    }

    private function select(array $document, array $selection): array
    {
        $wholeScenes = array_fill_keys($selection['scenes'] ?? [], true);
        $blocks = array_fill_keys($selection['blocks'] ?? [], true);
        $document['scenes'] = array_values(array_filter(array_map(
            static function (array $scene) use ($wholeScenes, $blocks): ?array {
                if (isset($wholeScenes[$scene['id']])) {
                    return $scene;
                }
                $scene['blocks'] = array_values(array_filter($scene['blocks'], static fn ($block) => isset($blocks[$block['id']])));
                return $scene['blocks'] ? $scene : null;
            },
            $document['scenes']
        )));
        if (!$document['scenes']) {
            throw new RuntimeException('No matching scenes or screenplay blocks to export.');
        }
        $sceneIds = array_column($document['scenes'], 'id');
        $keep = [
            'scenes' => $sceneIds,
            'acts' => array_filter(array_column($document['scenes'], 'act_id')),
            'episodes' => array_filter(array_column($document['scenes'], 'episode_id')),
            'characters' => [],
        ];
        $document['scene_character_links'] = array_values(array_filter(
            $document['scene_character_links'], static fn ($link) => in_array($link['scene_id'], $sceneIds, true)
        ));
        $keep['characters'] = array_column($document['scene_character_links'], 'character_id');
        foreach ($document['scenes'] as $scene) {
            array_push($keep['characters'], ...array_filter(array_column($scene['blocks'], 'speaker_id')));
        }
        foreach (['acts', 'episodes', 'characters'] as $kind) {
            $document[$kind] = array_values(array_filter($document[$kind], static fn ($row) => in_array($row['id'], $keep[$kind], true)));
        }
        foreach ($document['plot_threads'] as &$plot) {
            foreach (ManagementService::PLOT_LINKS as $kind) {
                $plot[$kind] = array_values(array_intersect($plot[$kind], $keep[$kind]));
            }
        }
        unset($plot);
        $document['plot_threads'] = array_values(array_filter($document['plot_threads'], static fn ($plot) => $plot['scenes'] || $plot['episodes'] || $plot['acts'] || $plot['characters']));
        $document['project']['title'] = mb_substr($document['project']['title'], 0, 243) . ' (selection)';
        return $document;
    }

    public function restore(int $user, array $input, string $source, string $format, ?string $destination = null): string
    {
        $document = ProjectDocument::validate($input);
        if (strlen($source) > ProjectDocument::MAX_BYTES || !in_array($format, ['json', 'paste', 'docx', 'fountain'], true)) {
            throw new RuntimeException('The source is too large or its format is unsupported.');
        }
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            if ($destination === null) {
                $info = $document['project'];
                $project = $this->scripts->createProject($user, $info['title'], $info['type'], $info['description']);
                $this->scripts->setProjectStatus($user, $project['uuid'], $info['status']);
            } else {
                $project = $this->scripts->project($user, $destination);
                $lock = $pdo->prepare('SELECT id FROM projects WHERE id = ? FOR UPDATE');
                $lock->execute([$project['id']]);
            }
            $this->restoreRows((int) $project['id'], $document, $destination !== null);
            $pdo->prepare('INSERT INTO import_records (project_id, source_type, original_source) VALUES (?, ?, ?)')
                ->execute([$project['id'], $format, $source]);
            $pdo->prepare('INSERT INTO revisions (project_id, actor_id, entity_type, entity_uuid, action, payload) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$project['id'], $user, 'project', $project['uuid'], 'imported', json_encode(['source_type' => $format, 'scenes' => count($document['scenes'])], JSON_THROW_ON_ERROR)]);
            $pdo->prepare('UPDATE projects SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$project['id']]);
            $pdo->commit();
            return $project['uuid'];
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }

    private function restoreRows(int $project, array $document, bool $append): void
    {
        $pdo = $this->db->pdo();
        $map = ['episodes' => [], 'acts' => [], 'characters' => [], 'scenes' => []];
        $findCharacter = $pdo->prepare('SELECT id FROM characters WHERE project_id = ? AND name = ?');
        $character = $pdo->prepare('INSERT INTO characters (uuid, project_id, name, description) VALUES (?, ?, ?, ?)');
        $alias = $pdo->prepare('INSERT INTO character_aliases (character_id, alias) VALUES (?, ?) ON DUPLICATE KEY UPDATE character_id = character_id');
        foreach ($document['characters'] as $row) {
            $findCharacter->execute([$project, $row['name']]);
            $id = $findCharacter->fetchColumn();
            if (!$id || !$append) {
                $character->execute([Uuid::v4(), $project, $row['name'], $row['description']]);
                $id = $pdo->lastInsertId();
            }
            $map['characters'][$row['id']] = $id;
            foreach ($row['aliases'] as $name) {
                $alias->execute([$id, $name]);
            }
        }

        $episode = $pdo->prepare('INSERT INTO episodes (uuid, project_id, title, position) VALUES (?, ?, ?, ?)');
        $act = $pdo->prepare('INSERT INTO acts (uuid, project_id, episode_id, title, position) VALUES (?, ?, ?, ?, ?)');
        $scene = $pdo->prepare('INSERT INTO scenes (uuid, project_id, episode_id, act_id, position, heading, int_ext, location, time_of_day, summary) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $block = $pdo->prepare('INSERT INTO content_blocks (uuid, scene_id, position, block_type, content, speaker_character_id, metadata) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $offset = [];
        foreach (['episodes', 'acts', 'scenes'] as $table) {
            $query = $pdo->prepare("SELECT COALESCE(MAX(position), 0) FROM $table WHERE project_id = ?");
            $query->execute([$project]);
            $offset[$table] = $append ? (int) $query->fetchColumn() : 0;
        }
        foreach ($document['episodes'] as $row) {
            $episode->execute([Uuid::v4(), $project, $row['title'], $row['position'] + $offset['episodes']]);
            $map['episodes'][$row['id']] = $pdo->lastInsertId();
        }
        foreach ($document['acts'] as $row) {
            $act->execute([Uuid::v4(), $project, $map['episodes'][$row['episode_id']] ?? null, $row['title'], $row['position'] + $offset['acts']]);
            $map['acts'][$row['id']] = $pdo->lastInsertId();
        }
        foreach ($document['scenes'] as $row) {
            $scene->execute([
                Uuid::v4(), $project, $map['episodes'][$row['episode_id']] ?? null, $map['acts'][$row['act_id']] ?? null,
                $row['position'] + $offset['scenes'], $row['heading'] ?? '', $row['int_ext'], $row['location'], $row['time_of_day'], $row['summary'],
            ]);
            $id = $pdo->lastInsertId();
            $map['scenes'][$row['id']] = $id;
            foreach ($row['blocks'] as $entry) {
                $block->execute([
                    Uuid::v4(), $id, $entry['position'], $entry['type'], $entry['text'], $map['characters'][$entry['speaker_id']] ?? null,
                    $entry['metadata'] === null ? null : json_encode($entry['metadata'], JSON_THROW_ON_ERROR),
                ]);
            }
        }
        $appearance = $pdo->prepare('INSERT INTO scene_characters (scene_id, character_id, association_type, notes) VALUES (?, ?, ?, ?)');
        foreach ($document['scene_character_links'] as $row) {
            $appearance->execute([$map['scenes'][$row['scene_id']], $map['characters'][$row['character_id']], $row['association_type'], $row['notes']]);
        }
        $thread = $pdo->prepare('INSERT INTO plot_threads (uuid, project_id, title, description) VALUES (?, ?, ?, ?)');
        foreach ($document['plot_threads'] as $row) {
            $thread->execute([Uuid::v4(), $project, $row['title'], $row['description']]);
            $id = $pdo->lastInsertId();
            foreach (ManagementService::PLOT_LINKS as $kind) {
                $link = $pdo->prepare("INSERT INTO plot_$kind (plot_id, entity_id) VALUES (?, ?)");
                foreach ($row[$kind] as $reference) {
                    $link->execute([$id, $map[$kind][$reference]]);
                }
            }
        }
    }
}
