<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Uuid;
use PDO;
use RuntimeException;

final class ScreenplayService
{
    private const BLOCK_TYPES = ['action', 'character', 'dialogue', 'parenthetical', 'transition', 'note'];
    private const PROJECT_TYPES = ['film', 'short_film', 'limited_series', 'tv_series', 'other'];

    public function __construct(private readonly Database $database)
    {
    }

    /** @return list<array<string, mixed>> */
    public function projectsForUser(int $userId, bool $includeArchived = false): array
    {
        $sql = 'SELECT p.*, (SELECT COUNT(*) FROM scenes s WHERE s.project_id = p.id) AS scene_count FROM projects p WHERE p.user_id = ?';
        if (!$includeArchived) {
            $sql .= " AND p.status = 'active'";
        }
        $sql .= ' ORDER BY p.updated_at DESC, p.id DESC';
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute([$userId]);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed> */
    public function createProject(int $userId, string $title, string $type, ?string $description): array
    {
        $title = $this->requiredText($title, 'Project title', 255);
        if (!in_array($type, self::PROJECT_TYPES, true)) {
            $type = 'film';
        }

        $uuid = Uuid::v4();
        $statement = $this->database->pdo()->prepare('INSERT INTO projects (uuid, user_id, title, project_type, description) VALUES (?, ?, ?, ?, ?)');
        $statement->execute([$uuid, $userId, $title, $type, $this->nullableText($description)]);
        return $this->project($userId, $uuid);
    }

    /** @return array<string, mixed> */
    public function project(int $userId, string $projectUuid): array
    {
        $statement = $this->database->pdo()->prepare('SELECT * FROM projects WHERE uuid = ? AND user_id = ? LIMIT 1');
        $statement->execute([$projectUuid, $userId]);
        $project = $statement->fetch();
        if (!is_array($project)) {
            throw new RuntimeException('Project not found.');
        }
        return $project;
    }

    /** @return array{project:array<string,mixed>, episodes:list<array<string,mixed>>, acts:list<array<string,mixed>>, scenes:list<array<string,mixed>>, characters:list<array<string,mixed>>, recentRevisions:list<array<string,mixed>>} */
    public function workspace(int $userId, string $projectUuid): array
    {
        $catalog = $this->catalog($userId, $projectUuid);
        $projectId = (int) $catalog['project']['id'];
        $pdo = $this->database->pdo();
        $scenes = $this->rows($pdo->prepare('SELECT s.* FROM scenes s LEFT JOIN episodes e ON e.id = s.episode_id LEFT JOIN acts a ON a.id = s.act_id WHERE s.project_id = ? ORDER BY s.episode_id IS NULL DESC, e.position, s.act_id IS NULL DESC, a.position, s.position, s.id'), [$projectId]);
        $recentRevisions = $this->rows($pdo->prepare('SELECT entity_type, action, created_at FROM revisions WHERE project_id = ? ORDER BY id DESC LIMIT 12'), [$projectId]);
        return $catalog + compact('scenes', 'recentRevisions');
    }

    public function catalog(int $userId, string $projectUuid): array
    {
        $project = $this->project($userId, $projectUuid);
        $pdo = $this->database->pdo();
        $params = [(int) $project['id']];
        $episodes = $this->rows($pdo->prepare('SELECT * FROM episodes WHERE project_id = ? ORDER BY position, id'), $params);
        $acts = $this->rows($pdo->prepare('SELECT a.* FROM acts a LEFT JOIN episodes e ON e.id = a.episode_id WHERE a.project_id = ? ORDER BY a.episode_id IS NULL DESC, e.position, a.position, a.id'), $params);
        $characters = $this->rows($pdo->prepare('SELECT * FROM characters WHERE project_id = ? ORDER BY name'), $params);
        return compact('project', 'episodes', 'acts', 'characters');
    }

    public function updateProject(int $userId, string $projectUuid, string $title, string $type, ?string $description): void
    {
        $project = $this->project($userId, $projectUuid);
        if (!in_array($type, self::PROJECT_TYPES, true)) {
            throw new RuntimeException('Invalid project type.');
        }
        $statement = $this->database->pdo()->prepare('UPDATE projects SET title = ?, project_type = ?, description = ? WHERE id = ?');
        $statement->execute([$this->requiredText($title, 'Project title', 255), $type, $this->nullableText($description), $project['id']]);
        $this->revision((int) $project['id'], $userId, 'project', $projectUuid, 'updated', ['title' => $title]);
    }

    public function setProjectStatus(int $userId, string $projectUuid, string $status): void
    {
        $project = $this->project($userId, $projectUuid);
        if (!in_array($status, ['active', 'archived'], true)) {
            throw new RuntimeException('Invalid project status.');
        }
        $statement = $this->database->pdo()->prepare('UPDATE projects SET status = ? WHERE id = ?');
        $statement->execute([$status, $project['id']]);
    }

    public function deleteProject(int $userId, string $projectUuid, string $confirmation): void
    {
        $project = $this->project($userId, $projectUuid);
        if (!hash_equals((string) $project['title'], trim($confirmation))) {
            throw new RuntimeException('Type the exact project title to delete it.');
        }
        /*
         * MySQL does not reliably permit a direct cascade where a scene can be
         * reached through both its project and its optional act/episode. Delete
         * the owned graph from leaves toward the project in one transaction.
         */
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM scenes WHERE project_id = ?')->execute([$project['id']]);
            $pdo->prepare('DELETE FROM acts WHERE project_id = ?')->execute([$project['id']]);
            $pdo->prepare('DELETE FROM episodes WHERE project_id = ?')->execute([$project['id']]);
            $pdo->prepare('DELETE FROM characters WHERE project_id = ?')->execute([$project['id']]);
            $pdo->prepare('DELETE FROM revisions WHERE project_id = ?')->execute([$project['id']]);
            $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$project['id']]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    public function createEpisode(int $userId, string $projectUuid, string $title): array
    {
        $project = $this->project($userId, $projectUuid);
        $pdo = $this->database->pdo();
        $position = $this->nextPosition('episodes', 'project_id = ?', [(int) $project['id']]);
        $uuid = Uuid::v4();
        $pdo->prepare('INSERT INTO episodes (uuid, project_id, title, position) VALUES (?, ?, ?, ?)')
            ->execute([$uuid, $project['id'], $this->requiredText($title, 'Episode title', 255), $position]);
        $this->revision((int) $project['id'], $userId, 'episode', $uuid, 'created', ['title' => $title]);
        return $this->recordByUuid('episodes', $uuid);
    }

    /** @return array<string,mixed> */
    public function createAct(int $userId, string $projectUuid, string $title, ?string $episodeUuid): array
    {
        $project = $this->project($userId, $projectUuid);
        $episode = $this->optionalRecordForProject('episodes', $episodeUuid, (int) $project['id']);
        $episodeId = $episode['id'] ?? null;
        $position = $this->nextPosition('acts', $episodeId === null ? 'project_id = ? AND episode_id IS NULL' : 'project_id = ? AND episode_id = ?', $episodeId === null ? [(int) $project['id']] : [(int) $project['id'], $episodeId]);
        $uuid = Uuid::v4();
        $this->database->pdo()->prepare('INSERT INTO acts (uuid, project_id, episode_id, title, position) VALUES (?, ?, ?, ?, ?)')
            ->execute([$uuid, $project['id'], $episodeId, $this->requiredText($title, 'Act title', 255), $position]);
        $this->revision((int) $project['id'], $userId, 'act', $uuid, 'created', ['title' => $title]);
        return $this->recordByUuid('acts', $uuid);
    }

    /** @return array<string,mixed> */
    public function createCharacter(int $userId, string $projectUuid, string $name, ?string $description): array
    {
        $project = $this->project($userId, $projectUuid);
        $uuid = Uuid::v4();
        try {
            $this->database->pdo()->prepare('INSERT INTO characters (uuid, project_id, name, description) VALUES (?, ?, ?, ?)')
                ->execute([$uuid, $project['id'], $this->requiredText($name, 'Character name', 255), $this->nullableText($description)]);
        } catch (\PDOException $exception) {
            throw new RuntimeException('A character with that name already exists in this project.', 0, $exception);
        }
        $this->revision((int) $project['id'], $userId, 'character', $uuid, 'created', ['name' => $name]);
        return $this->recordByUuid('characters', $uuid);
    }

    /** @return array<string,mixed> */
    public function createScene(int $userId, string $projectUuid, string $heading, ?string $episodeUuid, ?string $actUuid): array
    {
        $project = $this->project($userId, $projectUuid);
        [$episode, $act] = $this->resolveSceneScope((int) $project['id'], $episodeUuid, $actUuid);
        $position = $this->nextScenePosition((int) $project['id'], $episode['id'] ?? null, $act['id'] ?? null);
        $uuid = Uuid::v4();
        $this->database->pdo()->prepare('INSERT INTO scenes (uuid, project_id, episode_id, act_id, position, heading) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$uuid, $project['id'], $episode['id'] ?? null, $act['id'] ?? null, $position, $this->nullableText($heading) ?? 'UNTITLED SCENE']);
        $this->revision((int) $project['id'], $userId, 'scene', $uuid, 'created', ['heading' => $heading]);
        return $this->recordByUuid('scenes', $uuid);
    }

    /** @return array{scene:array<string,mixed>,project:array<string,mixed>,characters:list<array<string,mixed>>,episodes:list<array<string,mixed>>,acts:list<array<string,mixed>>,blocks:list<array<string,mixed>>} */
    public function sceneEditor(int $userId, string $projectUuid, string $sceneUuid): array
    {
        $project = $this->project($userId, $projectUuid);
        $scene = $this->recordForProject('scenes', $sceneUuid, (int) $project['id']);
        $pdo = $this->database->pdo();
        $characters = $this->rows($pdo->prepare('SELECT * FROM characters WHERE project_id = ? ORDER BY name'), [(int) $project['id']]);
        $episodes = $this->rows($pdo->prepare('SELECT * FROM episodes WHERE project_id = ? ORDER BY position'), [(int) $project['id']]);
        $acts = $this->rows($pdo->prepare('SELECT * FROM acts WHERE project_id = ? ORDER BY episode_id, position'), [(int) $project['id']]);
        $blocks = $this->rows($pdo->prepare('SELECT b.*, c.uuid AS speaker_uuid, c.name AS speaker_name FROM content_blocks b LEFT JOIN characters c ON c.id = b.speaker_character_id WHERE b.scene_id = ? ORDER BY b.position'), [(int) $scene['id']]);
        return compact('scene', 'project', 'characters', 'episodes', 'acts', 'blocks');
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    public function updateScene(int $userId, string $projectUuid, string $sceneUuid, array $changes, ?int $version = null): array
    {
        $project = $this->project($userId, $projectUuid);
        $allowed = ['heading', 'int_ext', 'location', 'time_of_day', 'summary', 'episode_uuid', 'act_uuid'];
        $field = array_key_first($changes);
        if (!is_string($field) || !in_array($field, $allowed, true)) {
            throw new RuntimeException('That scene field cannot be updated.');
        }
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT id FROM scenes WHERE uuid = ? AND project_id = ? FOR UPDATE');
            $lock->execute([$sceneUuid, $project['id']]);
            $lock->fetchColumn();
            $scene = $this->recordForProject('scenes', $sceneUuid, (int) $project['id']);
            if ($version !== null && $version !== (int) $scene['lock_version']) {
                throw new RuntimeException('This scene changed in another tab. Reload it before saving.');
            }
            if (in_array($field, ['episode_uuid', 'act_uuid'], true)) {
                $episodeUuid = $field === 'episode_uuid' ? (string) $changes[$field] : ($scene['episode_id'] ? $this->uuidForId('episodes', (int) $scene['episode_id']) : null);
                $actUuid = $field === 'act_uuid' ? (string) $changes[$field] : ($scene['act_id'] ? $this->uuidForId('acts', (int) $scene['act_id']) : null);
                [$episode, $act] = $this->resolveSceneScope((int) $project['id'], $episodeUuid, $actUuid);
                $newEpisodeId = $episode['id'] ?? null;
                $newActId = $act['id'] ?? null;
                $scopeChanged = $newEpisodeId != $scene['episode_id'] || $newActId != $scene['act_id'];
                $position = $scopeChanged ? $this->nextScenePosition((int) $project['id'], $newEpisodeId, $newActId) : (int) $scene['position'];
                $pdo->prepare('UPDATE scenes SET episode_id = ?, act_id = ?, position = ?, lock_version = lock_version + 1 WHERE id = ?')
                    ->execute([$newEpisodeId, $newActId, $position, $scene['id']]);
            } else {
                $value = $changes[$field];
                if ($field === 'heading') {
                    $value = $this->nullableText((string) $value) ?? 'UNTITLED SCENE';
                } elseif ($field === 'int_ext') {
                    $value = in_array($value, ['INT.', 'EXT.', 'INT./EXT.', 'OTHER', ''], true) ? ($value ?: null) : null;
                } elseif (in_array($field, ['location', 'time_of_day', 'summary'], true)) {
                    $value = $this->nullableText((string) $value);
                }
                $pdo->prepare("UPDATE scenes SET {$field} = ?, lock_version = lock_version + 1 WHERE id = ?")
                    ->execute([$value, $scene['id']]);
            }
            $this->touchProject((int) $project['id']);
            $updated = $this->recordByUuid('scenes', $sceneUuid);
            $this->revision((int) $project['id'], $userId, 'scene', $sceneUuid, 'updated', [$field => $changes[$field]]);
            $pdo->commit();
            return $updated;
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    public function duplicateScene(int $userId, string $projectUuid, string $sceneUuid): array
    {
        $project = $this->project($userId, $projectUuid);
        $scene = $this->recordForProject('scenes', $sceneUuid, (int) $project['id']);
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        try {
            $newUuid = Uuid::v4();
            $position = $this->nextScenePosition((int) $project['id'], $scene['episode_id'] ? (int) $scene['episode_id'] : null, $scene['act_id'] ? (int) $scene['act_id'] : null);
            $pdo->prepare('INSERT INTO scenes (uuid, project_id, episode_id, act_id, position, heading, int_ext, location, time_of_day, summary) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$newUuid, $project['id'], $scene['episode_id'], $scene['act_id'], $position, $scene['heading'] . ' (Copy)', $scene['int_ext'], $scene['location'], $scene['time_of_day'], $scene['summary']]);
            $newSceneId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO scene_characters (scene_id, character_id, association_type, notes) SELECT ?, character_id, association_type, notes FROM scene_characters WHERE scene_id = ?')
                ->execute([$newSceneId, $scene['id']]);
            $blocks = $this->rows($pdo->prepare('SELECT * FROM content_blocks WHERE scene_id = ? ORDER BY position'), [(int) $scene['id']]);
            foreach ($blocks as $block) {
                $pdo->prepare('INSERT INTO content_blocks (uuid, scene_id, position, block_type, content, speaker_character_id, metadata) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([Uuid::v4(), $newSceneId, $block['position'], $block['block_type'], $block['content'], $block['speaker_character_id'], $block['metadata']]);
            }
            $this->touchProject((int) $project['id']);
            $this->revision((int) $project['id'], $userId, 'scene', $newUuid, 'duplicated', ['source' => $sceneUuid]);
            $pdo->commit();
            return $this->recordByUuid('scenes', $newUuid);
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    public function createBlock(int $userId, string $projectUuid, string $sceneUuid, string $type, ?string $content = ''): array
    {
        $project = $this->project($userId, $projectUuid);
        $scene = $this->recordForProject('scenes', $sceneUuid, (int) $project['id']);
        if (!in_array($type, self::BLOCK_TYPES, true)) {
            throw new RuntimeException('Invalid block type.');
        }
        $uuid = Uuid::v4();
        $position = $this->nextPosition('content_blocks', 'scene_id = ?', [(int) $scene['id']]);
        $this->database->pdo()->prepare('INSERT INTO content_blocks (uuid, scene_id, position, block_type, content) VALUES (?, ?, ?, ?, ?)')
            ->execute([$uuid, $scene['id'], $position, $type, $content ?? '']);
        $this->touchProject((int) $project['id']);
        $this->revision((int) $project['id'], $userId, 'block', $uuid, 'created', ['type' => $type]);
        return $this->block($project, $uuid);
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    public function updateBlock(int $userId, string $projectUuid, string $blockUuid, array $changes, ?int $version): array
    {
        $project = $this->project($userId, $projectUuid);
        $field = array_key_first($changes);
        if (!in_array($field, ['content', 'block_type', 'speaker_uuid'], true)) {
            throw new RuntimeException('That block field cannot be updated.');
        }

        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT b.id FROM content_blocks b JOIN scenes s ON s.id = b.scene_id WHERE b.uuid = ? AND s.project_id = ? FOR UPDATE');
            $lock->execute([$blockUuid, $project['id']]);
            $lock->fetchColumn();
            $block = $this->block($project, $blockUuid);
            if ($version !== null && $version !== (int) $block['lock_version']) {
                throw new RuntimeException('This block changed in another tab. Reload it before saving.');
            }
            if ($field === 'content') {
                $value = (string) $changes['content'];
                if (mb_strlen($value) > 200000) {
                    throw new RuntimeException('A content block cannot exceed 200,000 characters.');
                }
                if ($value === $block['content']) {
                    $pdo->commit();
                    return $block;
                }
                $pdo->prepare('UPDATE content_blocks SET content = ?, lock_version = lock_version + 1 WHERE id = ?')->execute([$value, $block['id']]);
            } elseif ($field === 'block_type') {
                $value = (string) $changes['block_type'];
                if (!in_array($value, self::BLOCK_TYPES, true)) {
                    throw new RuntimeException('Invalid block type.');
                }
                $pdo->prepare('UPDATE content_blocks SET block_type = ?, speaker_character_id = CASE WHEN ? = \'dialogue\' THEN speaker_character_id ELSE NULL END, lock_version = lock_version + 1 WHERE id = ?')->execute([$value, $value, $block['id']]);
            } else {
                $character = $this->optionalRecordForProject('characters', (string) $changes['speaker_uuid'], (int) $project['id']);
                $pdo->prepare('UPDATE content_blocks SET speaker_character_id = ?, lock_version = lock_version + 1 WHERE id = ?')->execute([$character['id'] ?? null, $block['id']]);
            }
            $this->touchProject((int) $project['id']);
            $updated = $this->block($project, $blockUuid);
            $this->revision((int) $project['id'], $userId, 'block', $blockUuid, 'updated', [
                $field => $changes[$field],
                'previous' => $block[$field] ?? null,
            ]);
            $pdo->commit();
            return $updated;
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public function blockHistory(int $userId, string $projectUuid, string $blockUuid): array
    {
        $project = $this->project($userId, $projectUuid);
        $block = $this->block($project, $blockUuid);
        $rows = $this->rows($this->database->pdo()->prepare(
            "SELECT id, created_at, payload FROM revisions
             WHERE project_id = ? AND entity_type = 'block' AND entity_uuid = ? AND action = 'updated'
             AND JSON_CONTAINS_PATH(payload, 'one', '$.content') ORDER BY id DESC LIMIT 40"
        ), [$project['id'], $blockUuid]);
        foreach ($rows as &$row) {
            $payload = json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR);
            $row['text'] = $payload['previous'] ?? $payload['content'];
            $row['label'] = array_key_exists('previous', $payload) ? 'Before edit' : 'Saved version';
            unset($row['payload']);
        }
        return ['project' => $project, 'block' => $block, 'history' => $rows, 'scene' => $this->uuidForId('scenes', (int) $block['scene_id'])];
    }

    public function restoreBlock(int $userId, string $projectUuid, string $blockUuid, int $revisionId, int $version): void
    {
        $timeline = $this->blockHistory($userId, $projectUuid, $blockUuid);
        foreach ($timeline['history'] as $entry) {
            if ((int) $entry['id'] === $revisionId) {
                $this->updateBlock($userId, $projectUuid, $blockUuid, ['content' => $entry['text']], $version);
                return;
            }
        }
        throw new RuntimeException('That version is no longer available.');
    }

    public function deleteBlock(int $userId, string $projectUuid, string $blockUuid): void
    {
        $project = $this->project($userId, $projectUuid);
        $block = $this->block($project, $blockUuid);
        $this->database->pdo()->prepare('DELETE FROM content_blocks WHERE id = ?')->execute([$block['id']]);
        $this->resequenceBlocks((int) $block['scene_id']);
        $this->touchProject((int) $project['id']);
        $this->revision((int) $project['id'], $userId, 'block', $blockUuid, 'deleted', ['content' => $block['content']]);
    }

    public function moveBlock(int $userId, string $projectUuid, string $blockUuid, int $direction): void
    {
        $project = $this->project($userId, $projectUuid);
        $block = $this->block($project, $blockUuid);
        $operator = $direction < 0 ? '<' : '>';
        $sort = $direction < 0 ? 'DESC' : 'ASC';
        $statement = $this->database->pdo()->prepare("SELECT * FROM content_blocks WHERE scene_id = ? AND position {$operator} ? ORDER BY position {$sort} LIMIT 1");
        $statement->execute([$block['scene_id'], $block['position']]);
        $other = $statement->fetch();
        if (!is_array($other)) {
            return;
        }
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE content_blocks SET position = 0 WHERE id = ?')->execute([$block['id']]);
            $pdo->prepare('UPDATE content_blocks SET position = ? WHERE id = ?')->execute([$block['position'], $other['id']]);
            $pdo->prepare('UPDATE content_blocks SET position = ? WHERE id = ?')->execute([$other['position'], $block['id']]);
            $this->touchProject((int) $project['id']);
            $this->revision((int) $project['id'], $userId, 'block', $blockUuid, 'moved', ['direction' => $direction]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public function moveOrderedItem(int $userId, string $projectUuid, string $kind, string $uuid, int $direction): void
    {
        $project = $this->project($userId, $projectUuid);
        if (!in_array($kind, ['episode', 'act', 'scene'], true)) {
            throw new RuntimeException('Invalid item type.');
        }
        $table = $kind . 's';
        $record = $this->recordForProject($table, $uuid, (int) $project['id']);
        $where = 'project_id = ?';
        $bindings = [(int) $project['id']];
        if ($kind === 'act') {
            $where .= $record['episode_id'] === null ? ' AND episode_id IS NULL' : ' AND episode_id = ?';
            if ($record['episode_id'] !== null) $bindings[] = (int) $record['episode_id'];
        } elseif ($kind === 'scene') {
            $where .= $record['episode_id'] === null ? ' AND episode_id IS NULL' : ' AND episode_id = ?';
            if ($record['episode_id'] !== null) $bindings[] = (int) $record['episode_id'];
            $where .= $record['act_id'] === null ? ' AND act_id IS NULL' : ' AND act_id = ?';
            if ($record['act_id'] !== null) $bindings[] = (int) $record['act_id'];
        }
        $operator = $direction < 0 ? '<' : '>';
        $sort = $direction < 0 ? 'DESC' : 'ASC';
        $bindings[] = (int) $record['position'];
        $statement = $this->database->pdo()->prepare("SELECT * FROM {$table} WHERE {$where} AND position {$operator} ? ORDER BY position {$sort} LIMIT 1");
        $statement->execute($bindings);
        $other = $statement->fetch();
        if (!is_array($other)) return;
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE {$table} SET position = 0 WHERE id = ?")->execute([$record['id']]);
            $pdo->prepare("UPDATE {$table} SET position = ? WHERE id = ?")->execute([$record['position'], $other['id']]);
            $pdo->prepare("UPDATE {$table} SET position = ? WHERE id = ?")->execute([$other['position'], $record['id']]);
            $this->touchProject((int) $project['id']);
            $this->revision((int) $project['id'], $userId, $kind, $uuid, 'moved', ['direction' => $direction]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public function renameItem(int $userId, string $projectUuid, string $kind, string $uuid, string $title): void
    {
        $project = $this->project($userId, $projectUuid);
        $definition = match ($kind) {
            'episode' => ['episodes', 'title', 'Episode title'],
            'act' => ['acts', 'title', 'Act title'],
            'character' => ['characters', 'name', 'Character name'],
            default => throw new RuntimeException('Invalid item type.'),
        };
        [$table, $column, $label] = $definition;
        $record = $this->recordForProject($table, $uuid, (int) $project['id']);
        try {
            $this->database->pdo()->prepare("UPDATE {$table} SET {$column} = ? WHERE id = ?")
                ->execute([$this->requiredText($title, $label, 255), $record['id']]);
        } catch (\PDOException $exception) {
            throw new RuntimeException('That name is already in use for this project.', 0, $exception);
        }
        $this->touchProject((int) $project['id']);
        $this->revision((int) $project['id'], $userId, $kind, $uuid, 'renamed', [$column => $title]);
    }

    public function deleteItem(int $userId, string $projectUuid, string $kind, string $uuid): void
    {
        $project = $this->project($userId, $projectUuid);
        $table = match ($kind) { 'episode' => 'episodes', 'act' => 'acts', 'scene' => 'scenes', default => throw new RuntimeException('Invalid item type.') };
        $record = $this->recordForProject($table, $uuid, (int) $project['id']);
        $this->database->pdo()->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$record['id']]);
        $this->touchProject((int) $project['id']);
        $this->revision((int) $project['id'], $userId, $kind, $uuid, 'deleted', ['title' => $record['title'] ?? $record['heading'] ?? '']);
    }

    /** @return list<array<string,mixed>> */
    public function screenplay(int $userId, string $projectUuid): array
    {
        $project = $this->project($userId, $projectUuid);
        $sql = 'SELECT s.*, e.title AS episode_title, a.title AS act_title FROM scenes s LEFT JOIN episodes e ON e.id = s.episode_id LEFT JOIN acts a ON a.id = s.act_id WHERE s.project_id = ? ORDER BY s.episode_id IS NULL DESC, e.position, s.act_id IS NULL DESC, a.position, s.position, s.id';
        $scenes = $this->rows($this->database->pdo()->prepare($sql), [(int) $project['id']]);
        $blockQuery = $this->database->pdo()->prepare('SELECT b.*, c.name AS speaker_name FROM content_blocks b LEFT JOIN characters c ON c.id = b.speaker_character_id WHERE b.scene_id = ? ORDER BY b.position');
        foreach ($scenes as &$scene) {
            $scene['blocks'] = $this->rows($blockQuery, [(int) $scene['id']]);
        }
        unset($scene);
        return $scenes;
    }

    /** @return array<string,mixed> */
    private function block(array $project, string $blockUuid): array
    {
        $statement = $this->database->pdo()->prepare('SELECT b.*, s.project_id, c.uuid AS speaker_uuid, c.name AS speaker_name FROM content_blocks b INNER JOIN scenes s ON s.id = b.scene_id LEFT JOIN characters c ON c.id = b.speaker_character_id WHERE b.uuid = ? AND s.project_id = ? LIMIT 1');
        $statement->execute([$blockUuid, $project['id']]);
        $block = $statement->fetch();
        if (!is_array($block)) throw new RuntimeException('Content block not found.');
        return $block;
    }

    /** @return array<string,mixed> */
    private function recordForProject(string $table, string $uuid, int $projectId): array
    {
        $statement = $this->database->pdo()->prepare("SELECT * FROM {$table} WHERE uuid = ? AND project_id = ? LIMIT 1");
        $statement->execute([$uuid, $projectId]);
        $record = $statement->fetch();
        if (!is_array($record)) throw new RuntimeException('Record not found.');
        return $record;
    }

    /** @return array<string,mixed>|null */
    private function optionalRecordForProject(string $table, ?string $uuid, int $projectId): ?array
    {
        if ($uuid === null || trim($uuid) === '') return null;
        return $this->recordForProject($table, $uuid, $projectId);
    }

    /** @return array<string,mixed> */
    private function recordByUuid(string $table, string $uuid): array
    {
        $statement = $this->database->pdo()->prepare("SELECT * FROM {$table} WHERE uuid = ? LIMIT 1");
        $statement->execute([$uuid]);
        $record = $statement->fetch();
        if (!is_array($record)) throw new RuntimeException('Record not found.');
        return $record;
    }

    private function uuidForId(string $table, int $id): string
    {
        $statement = $this->database->pdo()->prepare("SELECT uuid FROM {$table} WHERE id = ?");
        $statement->execute([$id]);
        $uuid = $statement->fetchColumn();
        if (!is_string($uuid)) throw new RuntimeException('Related record no longer exists.');
        return $uuid;
    }

    /** @return array{0:array<string,mixed>|null,1:array<string,mixed>|null} */
    private function resolveSceneScope(int $projectId, ?string $episodeUuid, ?string $actUuid): array
    {
        $episode = $this->optionalRecordForProject('episodes', $episodeUuid, $projectId);
        $act = $this->optionalRecordForProject('acts', $actUuid, $projectId);
        if ($act !== null && $act['episode_id'] !== null) {
            if ($episode !== null && (int) $episode['id'] !== (int) $act['episode_id']) {
                throw new RuntimeException('The selected act belongs to another episode.');
            }
            $episode = $this->recordByUuid('episodes', $this->uuidForId('episodes', (int) $act['episode_id']));
        }
        return [$episode, $act];
    }

    private function nextPosition(string $table, string $where, array $bindings): int
    {
        $statement = $this->database->pdo()->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM {$table} WHERE {$where}");
        $statement->execute($bindings);
        return (int) $statement->fetchColumn();
    }

    private function nextScenePosition(int $projectId, ?int $episodeId, ?int $actId): int
    {
        $where = 'project_id = ?'; $bindings = [$projectId];
        $where .= $episodeId === null ? ' AND episode_id IS NULL' : ' AND episode_id = ?';
        if ($episodeId !== null) $bindings[] = $episodeId;
        $where .= $actId === null ? ' AND act_id IS NULL' : ' AND act_id = ?';
        if ($actId !== null) $bindings[] = $actId;
        return $this->nextPosition('scenes', $where, $bindings);
    }

    private function resequenceBlocks(int $sceneId): void
    {
        $blocks = $this->rows($this->database->pdo()->prepare('SELECT id FROM content_blocks WHERE scene_id = ? ORDER BY position, id'), [$sceneId]);
        $statement = $this->database->pdo()->prepare('UPDATE content_blocks SET position = ? WHERE id = ?');
        foreach ($blocks as $index => $block) $statement->execute([$index + 1, $block['id']]);
    }

    private function touchProject(int $projectId): void
    {
        $this->database->pdo()->prepare('UPDATE projects SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$projectId]);
    }

    /** @param array<string,mixed> $payload */
    private function revision(int $projectId, int $userId, string $entityType, string $entityUuid, string $action, array $payload): void
    {
        $this->database->pdo()->prepare('INSERT INTO revisions (project_id, actor_id, entity_type, entity_uuid, action, payload) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$projectId, $userId, $entityType, $entityUuid, $action, json_encode($payload, JSON_THROW_ON_ERROR)]);
    }

    /** @return list<array<string,mixed>> */
    private function rows(\PDOStatement $statement, array $bindings): array
    {
        $statement->execute($bindings);
        return $statement->fetchAll();
    }

    private function requiredText(string $value, string $label, int $limit): string
    {
        $value = trim($value);
        if ($value === '') throw new RuntimeException("{$label} is required.");
        if (mb_strlen($value) > $limit) throw new RuntimeException("{$label} is too long.");
        return $value;
    }

    private function nullableText(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
