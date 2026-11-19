<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\ManagementService;
use App\Services\PasteParser;
use App\Services\ProjectDocument;
use App\Services\ProjectTransfer;
use App\Services\ScreenplayService;
use App\Support\Uuid;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Check failed: ' . $message);
    }
}

function rejected(callable $test, string $expected): void
{
    try {
        $test();
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), $expected), $expected . ' (found: ' . $error->getMessage() . ')');
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $expected);
}

$pdo = $database->pdo();
$pdo->prepare('INSERT INTO users (uuid, email, password_hash) VALUES (?, ?, ?)')->execute([
    Uuid::v4(), 'milestone2-' . bin2hex(random_bytes(9)) . '@example.test', password_hash('only-a-test-password', PASSWORD_DEFAULT),
]);
$user = (int) $pdo->lastInsertId();
$scripts = new ScreenplayService($database);
$management = new ManagementService($database, $scripts);
$transfer = new ProjectTransfer($database, $scripts, $management);

try {
    $source = "Episode 1\nAct 1 (Hostel Room)\nAYO\n(quietly)\nI can pay 50%.\n\nScene 4 - Hostel\nAyo waits by the door.\n\nINT. HOSPITAL - NIGHT\nThe corridor is empty.";
    $parsed = PasteParser::parse('Management fixture', $source);
    $draft = $parsed['document'];
    check(!$scripts->projectsForUser($user), 'parsing does not create a project');
    check(count($draft['scenes']) === 3 && count($parsed['flags']) >= 3, 'irregular headings and uncertain cues');
    check($draft['scenes'][0]['blocks'][2]['type'] === 'dialogue', 'conventional dialogue detection');
    $draft['scenes'][0]['heading'] = 'INT. HOSTEL ROOM - NIGHT';
    $draft['scenes'][0]['blocks'][2]['text'] = 'We can pay 50%.';
    $uuid = $transfer->restore($user, $draft, $source, 'paste');
    $project = $scripts->workspace($user, $uuid);
    $person = $project['characters'][0];
    $scene = $project['scenes'][0];
    $location = $project['scenes'][2];
    $episode = $project['episodes'][0];
    $act = $project['acts'][0];
    $management->saveCharacter($user, $uuid, $person['uuid'], 'AYO', 'Student', "Ay\nAyo Jr.");
    $management->linkCharacter($user, $uuid, $location['uuid'], $person['uuid'], 'present', 'Outside the ward');
    $plot = $management->savePlot($user, $uuid, '', 'Money trouble', 'Financial pressure', [
        'scenes' => [$scene['uuid'], $location['uuid']], 'characters' => [$person['uuid']], 'episodes' => [$episode['uuid']], 'acts' => [$act['uuid']],
    ]);
    $speech = $management->search($user, $uuid, [
        'character' => $person['uuid'], 'relation' => 'dialogue', 'act' => $act['uuid'], 'plot' => $plot, 'type' => 'dialogue', 'q' => '50%',
    ]);
    check(count($speech) === 1 && $speech[0]['content'] === 'We can pay 50%.', 'literal phrase, scoped character and plot search');
    check(count($management->search($user, $uuid, ['q' => 'Ayo Jr.', 'relation' => 'dialogue', 'character' => $person['uuid']])) === 1, 'alias search');
    check(count($management->search($user, $uuid, ['character' => $person['uuid'], 'relation' => 'mentioned', 'view' => 'scenes'])) === 1, 'action mention stays separate');
    check(count($management->search($user, $uuid, ['q' => 'pay', 'type' => 'action', 'view' => 'scenes'])) === 0, 'scene search respects the requested block type');
    check($management->search($user, $uuid, ['character' => $person['uuid'], 'relation' => 'associated', 'view' => 'scenes', 'location' => 'Hospital'])[0]['scene_uuid'] === $location['uuid'], 'explicit appearance and location');
    $management->saveCharacter($user, $uuid, $person['uuid'], 'AYODELE', 'Student', "Ay\nAyo Jr.");
    $line = $speech[0];
    $change = $scripts->updateBlock($user, $uuid, $line['block_uuid'], ['content' => 'We can pay tomorrow.'], (int) $line['block_version']);
    $editorBlock = $scripts->sceneEditor($user, $uuid, $scene['uuid'])['blocks'][2];
    check($editorBlock['content'] === 'We can pay tomorrow.' && $editorBlock['speaker_name'] === 'AYODELE', 'focused edit and rename update authoritative scene');
    rejected(fn () => $scripts->updateBlock($user, $uuid, $line['block_uuid'], ['content' => 'stale'], (int) $line['block_version']), 'another tab');
    $history = $scripts->blockHistory($user, $uuid, $line['block_uuid'])['history'];
    $scripts->restoreBlock($user, $uuid, $line['block_uuid'], (int) $history[0]['id'], (int) $change['lock_version']);
    check($scripts->sceneEditor($user, $uuid, $scene['uuid'])['blocks'][2]['content'] === $line['content'], 'revision restore');
    check(count($management->search($user, $uuid, ['view' => 'scenes', 'plot' => $plot])) === 2, 'plot has the two explicitly linked scenes');
    rejected(fn () => $management->search($user + 1, $uuid, []), 'Project not found');

    $partial = $transfer->backup($user, $uuid, ['blocks' => [$line['block_uuid']]]);
    check(count($partial['scenes']) === 1 && count($partial['scenes'][0]['blocks']) === 1, 'checked-block export omits other material');
    ProjectDocument::validate($partial);
    $json = json_encode($transfer->backup($user, $uuid), JSON_THROW_ON_ERROR);
    $backup = ProjectDocument::decode($json);
    $restored = $transfer->restore($user, $backup, $json, 'json');
    $new = $transfer->backup($user, $restored);
    check($uuid !== $restored && $new['scenes'][0]['id'] !== $backup['scenes'][0]['id'], 'restore uses new UUIDs');
    check(count($new['scenes']) === 3 && $new['scenes'][0]['blocks'][2]['text'] === $line['content'], 'full backup round trip');
    check($new['scenes'][0]['blocks'][2]['speaker_id'] === $new['characters'][0]['id'], 'restored speaker references remapped');
    check(count($new['plot_threads'][0]['scenes']) === 2 && count($new['plot_threads'][0]['characters']) === 1 && count($new['plot_threads'][0]['episodes']) === 1 && count($new['plot_threads'][0]['acts']) === 1, 'all plot link types round trip');
    check($new['scene_character_links'][0]['notes'] === 'Outside the ward' && count($new['characters'][0]['aliases']) === 2, 'appearance notes and aliases round trip');
    $transfer->restore($user, $backup, $json, 'json', $uuid);
    check(count($transfer->backup($user, $uuid)['scenes']) === 6 && count($transfer->backup($user, $uuid)['characters']) === 1, 'append retains scenes and reuses matching characters');
    $defaults = ['title' => $project['project']['title']];
    $actJson = '{"title":"Act 2","scenes":[{"heading":"EXT. ROAD - DAWN","blocks":[{"type":"action","text":"A car pulls away."}]}]}';
    $actDocument = ProjectDocument::decode($actJson, $defaults);
    $transfer->restore($user, $actDocument, $actJson, 'json', $uuid, 'episode:' . $episode['uuid']);
    $withAct = $transfer->backup($user, $uuid);
    $addedAct = array_column($withAct['acts'], null, 'title')['Act 2'];
    check($addedAct['episode_id'] === $episode['uuid'] && count($withAct['episodes']) === 2, 'new act joins an existing episode');

    $sceneJson = '{"heading":"INT. CAR - DAWN","characters":[{"id":"driver","name":"AYODELE"}],"blocks":[{"type":"dialogue","text":"We are late.","speaker_id":"driver"}]}';
    $sceneDocument = ProjectDocument::decode($sceneJson, $defaults);
    rejected(fn () => $transfer->restore($user, $sceneDocument, $sceneJson, 'json', $uuid, 'act:' . $new['acts'][0]['id']), 'no longer available');
    $transfer->restore($user, $sceneDocument, $sceneJson, 'json', $uuid, 'act:' . $addedAct['id']);
    $transfer->restore($user, ProjectDocument::validate($partial), $json, 'json', $uuid, 'act:' . $addedAct['id']);
    $updated = $transfer->backup($user, $uuid);
    $addedScenes = array_values(array_filter($updated['scenes'], fn ($row) => $row['act_id'] === $addedAct['id']));
    check(count($addedScenes) === 3 && count($updated['scenes']) === 9 && count($updated['acts']) === 3, 'section imports append without duplicating the destination act or losing existing scenes');
    check($addedScenes[1]['heading'] === 'INT. CAR - DAWN' && $addedScenes[1]['episode_id'] === $episode['uuid'], 'standalone scene follows the previous scene and inherits its act episode');
    check($addedScenes[1]['blocks'][0]['speaker_id'] === $person['uuid'] && count($updated['characters']) === 1, 'section dialogue reuses an existing character');
    rejected(fn () => ProjectDocument::decode('{broken'), 'Invalid JSON');
    $bad = $backup;
    $bad['scenes'][0]['blocks'][2]['speaker_id'] = 'missing';
    rejected(fn () => $transfer->restore($user, $bad, $json, 'json'), 'missing');
    $nested = ProjectDocument::decode('{"schema_version":"1.0","project":{"title":"Nested"},"characters":[{"key":"p","name":"PAT"}],"acts":[{"title":"Act 1","scenes":[{"heading":"INT. TEST","blocks":[{"type":"dialogue","text":"Hello","speaker":"p"}]}]}]}');
    check($nested['scenes'][0]['blocks'][0]['speaker_id'] === 'p' && $nested['scenes'][0]['act_id'] === $nested['acts'][0]['id'], 'initial nested example can be imported');
    $bad = $backup;
    $bad['characters'][] = ['id' => 'case-accent', 'name' => 'AYÓDELE'];
    $count = count($scripts->projectsForUser($user));
    try {
        $transfer->restore($user, $bad, $json, 'json');
        throw new RuntimeException('Expected a database uniqueness error.');
    } catch (PDOException) {
        check(count($scripts->projectsForUser($user)) === $count, 'failed transaction does not leave a new project');
    }

    $large = ['schema_version' => ProjectDocument::VERSION, 'project' => ['title' => 'Long screenplay'], 'scenes' => []];
    for ($sceneIndex = 1; $sceneIndex <= 320; $sceneIndex++) {
        $blocks = [];
        for ($blockIndex = 1; $blockIndex <= 10; $blockIndex++) {
            $blocks[] = ['id' => "block-$sceneIndex-$blockIndex", 'type' => 'action', 'text' => "Long screenplay page $sceneIndex, line $blockIndex"];
        }
        $large['scenes'][] = ['id' => "scene-$sceneIndex", 'heading' => "INT. TEST ROOM $sceneIndex - NIGHT", 'blocks' => $blocks];
    }
    $largeUuid = $transfer->restore($user, $large, 'Test fixture', 'json');
    $pageOne = $management->search($user, $largeUuid, ['q' => 'Long screenplay page']);
    $pageTwo = $management->search($user, $largeUuid, ['q' => 'Long screenplay page', 'page' => 2]);
    check(count($pageOne) === 31 && count($pageTwo) === 31, 'long screenplay results are paginated');
    check($pageOne[30]['block_uuid'] === $pageTwo[0]['block_uuid'], 'lookahead and next page follow the same stable ordering');
    check(count($transfer->backup($user, $largeUuid)['scenes']) === 320, 'large backup retains all scenes');
    echo "Management workflow passed.\n";
} finally {
    foreach ($scripts->projectsForUser($user, true) as $project) {
        $scripts->deleteProject($user, $project['uuid'], $project['title']);
    }
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$user]);
}
