<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\ScreenplayService;
use App\Support\Uuid;

/** @var App\Support\Database $database */
$pdo = $database->pdo();
$email = 'integration-' . bin2hex(random_bytes(6)) . '@example.test';
$userId = 0;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("Assertion failed: {$message}");
    }
};

try {
    $pdo->prepare('INSERT INTO users (uuid, email, password_hash) VALUES (?, ?, ?)')
        ->execute([Uuid::v4(), $email, password_hash('test-only-password', PASSWORD_DEFAULT)]);
    $userId = (int) $pdo->lastInsertId();
    $service = new ScreenplayService($database);

    $project = $service->createProject($userId, 'Integration Script', 'film', 'Temporary workflow test');
    $episode = $service->createEpisode($userId, $project['uuid'], 'Episode One');
    $act = $service->createAct($userId, $project['uuid'], 'Act One', $episode['uuid']);
    $character = $service->createCharacter($userId, $project['uuid'], 'MARA', 'Lead character');
    $scene = $service->createScene($userId, $project['uuid'], 'INT. TEST ROOM - NIGHT', $episode['uuid'], $act['uuid']);
    $service->createBlock($userId, $project['uuid'], $scene['uuid'], 'action', 'Mara waits by the door.');
    $second = $service->createBlock($userId, $project['uuid'], $scene['uuid'], 'dialogue', 'Are you there?');
    $second = $service->updateBlock($userId, $project['uuid'], $second['uuid'], ['speaker_uuid' => $character['uuid']], (int) $second['lock_version']);
    $second = $service->updateBlock($userId, $project['uuid'], $second['uuid'], ['content' => 'Are you there, Sam?'], (int) $second['lock_version']);
    $service->moveBlock($userId, $project['uuid'], $second['uuid'], -1);
    $updatedScene = $service->updateScene($userId, $project['uuid'], $scene['uuid'], ['location' => 'Test Room'], 1);
    $copy = $service->duplicateScene($userId, $project['uuid'], $scene['uuid']);
    $screenplay = $service->screenplay($userId, $project['uuid']);

    $assert(count($screenplay) === 2, 'duplicating a scene preserves it in the screenplay');
    $assert($updatedScene['location'] === 'Test Room', 'scene updates persist');
    $assert((int) $second['speaker_character_id'] === (int) $character['id'], 'dialogue retains a character ID');
    $assert($copy['uuid'] !== $scene['uuid'], 'duplicate receives a new stable UUID');
    $assert(count($screenplay[0]['blocks']) === 2, 'scene blocks are retained');
    $assert($screenplay[0]['blocks'][0]['content'] === 'Are you there, Sam?', 'reordered dialogue is authoritative');

    $service->deleteProject($userId, $project['uuid'], 'Integration Script');
    echo "Integration workflow passed.\n";
} finally {
    if ($userId > 0) {
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    }
}
