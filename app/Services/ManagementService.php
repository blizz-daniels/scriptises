<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Uuid;
use RuntimeException;

final class ManagementService
{
    public const BLOCK_TYPES = ['action', 'character', 'dialogue', 'parenthetical', 'transition', 'note'];
    public const PLOT_LINKS = ['scenes', 'characters', 'acts', 'episodes'];

    public function __construct(private readonly Database $db, private readonly ScreenplayService $scripts)
    {
    }

    public function rows(string $sql, array $parameters): array
    {
        $query = $this->db->pdo()->prepare($sql);
        $query->execute($parameters);
        return $query->fetchAll();
    }

    public function plots(int $projectId): array
    {
        return $this->rows('SELECT * FROM plot_threads WHERE project_id = ? ORDER BY title, id', [$projectId]);
    }

    public function search(int $user, string $projectUuid, array $filters, bool $all = false): array
    {
        $project = $this->scripts->project($user, $projectUuid);
        $blocks = ($filters['view'] ?? '') !== 'scenes';
        $joinBlocks = $blocks
            ? 'JOIN content_blocks b ON b.scene_id = s.id LEFT JOIN characters c ON c.id = b.speaker_character_id'
            : '';
        $blockFields = $blocks ? ', b.uuid AS block_uuid, b.block_type, b.content, b.lock_version AS block_version, c.name AS speaker' : '';
        $conditions = ['s.project_id = ?'];
        $params = [$project['id']];
        foreach (['episode' => 'e.uuid', 'act' => 'a.uuid'] as $filter => $column) {
            if (!empty($filters[$filter])) {
                $conditions[] = "$column = ?";
                $params[] = $filters[$filter];
            }
        }
        if (!empty($filters['plot'])) {
            $conditions[] = 'EXISTS (SELECT 1 FROM plot_scenes ps JOIN plot_threads p ON p.id = ps.plot_id WHERE ps.entity_id = s.id AND p.project_id = s.project_id AND p.uuid = ?)';
            $params[] = $filters['plot'];
        }
        if ($blocks && in_array($filters['type'] ?? '', self::BLOCK_TYPES, true)) {
            $conditions[] = 'b.block_type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['character'])) {
            $character = $this->owned('characters', $project['id'], $filters['character']);
            $relation = $filters['relation'] ?? '';
            if ($relation === 'mentioned') {
                $names = array_column($this->rows('SELECT alias FROM character_aliases WHERE character_id = ?', [$character['id']]), 'alias');
                $names[] = $character['name'];
                $mention = implode(' OR ', array_fill(0, count($names), "action_block.content LIKE ? ESCAPE '!'"));
                $conditions[] = "EXISTS (SELECT 1 FROM content_blocks action_block WHERE action_block.scene_id = s.id AND action_block.block_type = 'action' AND ($mention))";
                array_push($params, ...array_map($this->contains(...), $names));
            } else {
                $spoken = "EXISTS (SELECT 1 FROM content_blocks speech WHERE speech.scene_id = s.id AND speech.block_type = 'dialogue' AND speech.speaker_character_id = ?)";
                $associated = 'EXISTS (SELECT 1 FROM scene_characters sc WHERE sc.scene_id = s.id AND sc.character_id = ?)';
                if ($relation === 'dialogue' && $blocks) {
                    $conditions[] = "b.block_type = 'dialogue' AND b.speaker_character_id = ?";
                } elseif ($relation === 'dialogue') {
                    $conditions[] = $spoken;
                } elseif ($relation === 'associated') {
                    $conditions[] = $associated;
                } else {
                    $conditions[] = "($spoken OR $associated)";
                    $params[] = $character['id'];
                }
                $params[] = $character['id'];
            }
        }
        if (($filters['location'] ?? '') !== '') {
            $conditions[] = "(s.location LIKE ? ESCAPE '!' OR (COALESCE(s.location, '') = '' AND s.heading LIKE ? ESCAPE '!'))";
            $location = $this->contains($filters['location']);
            array_push($params, $location, $location);
        }
        if (($filters['q'] ?? '') !== '') {
            $fields = ['s.heading', 's.summary', 's.location', 's.time_of_day', 'e.title', 'a.title'];
            if ($blocks) {
                array_push($fields, 'b.content', 'c.name');
            }
            $matches = array_map(static fn ($field) => "$field LIKE ? ESCAPE '!'", $fields);
            $term = $this->contains($filters['q']);
            array_push($params, ...array_fill(0, count($matches), $term));
            if (!$blocks) {
                $matches[] = "EXISTS (SELECT 1 FROM content_blocks cb LEFT JOIN characters sp ON sp.id = cb.speaker_character_id WHERE cb.scene_id = s.id AND (cb.content LIKE ? ESCAPE '!' OR sp.name LIKE ? ESCAPE '!'))";
                array_push($params, $term, $term);
                $matches[] = "EXISTS (SELECT 1 FROM content_blocks cb JOIN character_aliases ca ON ca.character_id = cb.speaker_character_id WHERE cb.scene_id = s.id AND ca.alias LIKE ? ESCAPE '!')";
            } else {
                $matches[] = "EXISTS (SELECT 1 FROM character_aliases ca WHERE ca.character_id = b.speaker_character_id AND ca.alias LIKE ? ESCAPE '!')";
            }
            $params[] = $term;
            $matches[] = "EXISTS (SELECT 1 FROM scene_characters sc JOIN characters person ON person.id = sc.character_id LEFT JOIN character_aliases ca ON ca.character_id = person.id WHERE sc.scene_id = s.id AND (person.name LIKE ? ESCAPE '!' OR ca.alias LIKE ? ESCAPE '!'))";
            array_push($params, $term, $term);
            $matches[] = "EXISTS (SELECT 1 FROM plot_scenes ps JOIN plot_threads pt ON pt.id = ps.plot_id WHERE ps.entity_id = s.id AND pt.title LIKE ? ESCAPE '!')";
            $params[] = $term;
            $conditions[] = '(' . implode(' OR ', $matches) . ')';
        }
        $where = implode(' AND ', $conditions);
        $page = max(1, min(100000, (int) ($filters['page'] ?? 1)));
        $limit = $all ? '' : ' LIMIT 31 OFFSET ' . (($page - 1) * 30);
        $order = 's.episode_id IS NULL DESC, e.position, s.act_id IS NULL DESC, a.position, s.position, s.id';
        if ($blocks) {
            $order .= ', b.position';
        }
        return $this->rows(
            "SELECT s.uuid AS scene_uuid, s.heading, s.location, s.lock_version AS scene_version,
             e.title AS episode, a.title AS act $blockFields
             FROM scenes s LEFT JOIN episodes e ON e.id = s.episode_id LEFT JOIN acts a ON a.id = s.act_id
             $joinBlocks WHERE $where ORDER BY $order $limit",
            $params
        );
    }

    public function character(int $projectId, string $id): array
    {
        $character = $this->owned('characters', $projectId, $id);
        $character['aliases'] = array_column($this->rows('SELECT alias FROM character_aliases WHERE character_id = ? ORDER BY alias', [$character['id']]), 'alias');
        return $character;
    }

    public function saveCharacter(int $user, string $projectUuid, string $id, string $name, string $bio, string $aliases): void
    {
        $project = $this->scripts->project($user, $projectUuid);
        $character = $this->owned('characters', $project['id'], $id);
        $name = $this->title($name);
        if (strlen($bio) > 60000 || strlen($aliases) > 12000) {
            throw new RuntimeException('The biography or aliases are too long.');
        }
        $names = array_unique(array_filter(array_map('trim', preg_split('/\R/u', $aliases) ?: []), 'strlen'));
        $pdo = $this->db->pdo();
        $this->transaction(function () use ($pdo, $name, $bio, $names, $character): void {
            $pdo->prepare('UPDATE characters SET name = ?, description = ? WHERE id = ?')->execute([$name, $bio, $character['id']]);
            $pdo->prepare('DELETE FROM character_aliases WHERE character_id = ?')->execute([$character['id']]);
            $insert = $pdo->prepare('INSERT INTO character_aliases (character_id, alias) VALUES (?, ?) ON DUPLICATE KEY UPDATE character_id = character_id');
            foreach ($names as $alias) {
                $insert->execute([$character['id'], $this->title($alias)]);
            }
        });
    }

    public function sceneCharacters(int $projectId, string $scene): array
    {
        $row = $this->owned('scenes', $projectId, $scene);
        return $this->rows(
            'SELECT c.uuid, c.name, sc.association_type, sc.notes FROM scene_characters sc JOIN characters c ON c.id = sc.character_id WHERE sc.scene_id = ? ORDER BY c.name',
            [$row['id']]
        );
    }

    public function linkCharacter(int $user, string $projectUuid, string $scene, string $character, string $relation, string $notes): void
    {
        $project = $this->scripts->project($user, $projectUuid);
        $sceneRow = $this->owned('scenes', $project['id'], $scene);
        $person = $this->owned('characters', $project['id'], $character);
        $pdo = $this->db->pdo();
        if ($relation === 'remove') {
            $pdo->prepare('DELETE FROM scene_characters WHERE scene_id = ? AND character_id = ?')->execute([$sceneRow['id'], $person['id']]);
            return;
        }
        if (!in_array($relation, ['present', 'associated'], true) || mb_strlen($notes) > 500) {
            throw new RuntimeException('Choose a valid relationship and use no more than 500 characters for notes.');
        }
        $pdo->prepare(
            'INSERT INTO scene_characters (scene_id, character_id, association_type, notes) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE association_type = ?, notes = ?'
        )->execute([$sceneRow['id'], $person['id'], $relation, $notes, $relation, $notes]);
    }

    public function plot(int $projectId, string $uuid): array
    {
        $plot = $this->owned('plot_threads', $projectId, $uuid);
        foreach (self::PLOT_LINKS as $type) {
            $plot[$type] = array_column($this->rows(
                "SELECT e.uuid FROM plot_$type l JOIN $type e ON e.id = l.entity_id WHERE l.plot_id = ?", [$plot['id']]
            ), 'uuid');
        }
        return $plot;
    }

    public function savePlot(int $user, string $projectUuid, string $uuid, string $title, string $description, array $links): string
    {
        $project = $this->scripts->project($user, $projectUuid);
        $title = $this->title($title);
        if (strlen($description) > 60000) {
            throw new RuntimeException('The plot description is too long.');
        }
        $pdo = $this->db->pdo();
        return $this->transaction(function () use ($pdo, $project, $uuid, $title, $description, $links): string {
            if ($uuid === '') {
                $uuid = Uuid::v4();
                $pdo->prepare('INSERT INTO plot_threads (uuid, project_id, title, description) VALUES (?, ?, ?, ?)')
                    ->execute([$uuid, $project['id'], $title, $description]);
                $plotId = $pdo->lastInsertId();
            } else {
                $plotId = $this->owned('plot_threads', $project['id'], $uuid)['id'];
                $pdo->prepare('UPDATE plot_threads SET title = ?, description = ? WHERE id = ?')->execute([$title, $description, $plotId]);
            }
            foreach (self::PLOT_LINKS as $type) {
                $pdo->prepare("DELETE FROM plot_$type WHERE plot_id = ?")->execute([$plotId]);
                $insert = $pdo->prepare("INSERT INTO plot_$type (plot_id, entity_id) VALUES (?, ?)");
                foreach (array_unique($links[$type] ?? []) as $id) {
                    $insert->execute([$plotId, $this->owned($type, $project['id'], $id)['id']]);
                }
            }
            return $uuid;
        });
    }

    public function deletePlot(int $user, string $projectUuid, string $uuid): void
    {
        $project = $this->scripts->project($user, $projectUuid);
        $this->db->pdo()->prepare('DELETE FROM plot_threads WHERE project_id = ? AND uuid = ?')->execute([$project['id'], $uuid]);
    }

    private function owned(string $table, int $project, string $uuid): array
    {
        return $this->rows("SELECT * FROM $table WHERE project_id = ? AND uuid = ?", [$project, $uuid])[0]
            ?? throw new RuntimeException('The selected item does not belong to this project.');
    }

    private function title(string $text): string
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 255) {
            throw new RuntimeException('Names and titles must have between 1 and 255 characters.');
        }
        return $text;
    }

    private function contains(string $text): string
    {
        return '%' . strtr($text, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }

    private function transaction(callable $work): mixed
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();
            return $result;
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }
}
