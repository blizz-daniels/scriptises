<?php
require __DIR__ . '/partials/header.php';
$e = static fn (mixed $value): string => \App\Support\View::escape((string) $value);
$csrf = $e(\App\Support\Csrf::token());
$projectUuid = $e($project['uuid']); $sceneUuid = $e($scene['uuid']);
$sceneEndpoint = '/api/projects/' . $projectUuid . '/scenes/' . $sceneUuid;
?>
<section class="editor-shell" data-editor>
    <div class="editor-bar">
        <div><a class="back-link" href="/projects/<?= $projectUuid ?>">‹ <?= $e($project['title']) ?></a><span class="save-status" data-save-status>Saved</span></div>
        <div class="editor-actions"><button class="button small" type="button" data-editor-undo>Undo</button><button class="button small" type="button" data-editor-redo>Redo</button><button class="button small" type="button" data-save-now>Save now</button><form method="post" action="/projects/<?= $projectUuid ?>/scenes/<?= $sceneUuid ?>/duplicate"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><button class="button small" type="submit">Duplicate scene</button></form><a class="button small" href="/projects/<?= $projectUuid ?>/read">Read</a></div>
    </div>
    <div class="editor-layout">
        <aside class="scene-details">
            <p class="eyebrow">Scene details</p>
            <label>Heading <input value="<?= $e($scene['heading']) ?>" data-autosave data-endpoint="<?= $e($sceneEndpoint) ?>" data-field="heading" data-version="<?= (int) $scene['lock_version'] ?>"></label>
            <label>Interior / exterior <select data-autosave data-endpoint="<?= $e($sceneEndpoint) ?>" data-field="int_ext" data-version="<?= (int) $scene['lock_version'] ?>"><option value="">Not specified</option><?php foreach (['INT.', 'EXT.', 'INT./EXT.', 'OTHER'] as $option): ?><option value="<?= $e($option) ?>" <?= $scene['int_ext'] === $option ? 'selected' : '' ?>><?= $e($option) ?></option><?php endforeach; ?></select></label>
            <label>Location <input value="<?= $e($scene['location']) ?>" placeholder="Hostel room" data-autosave data-endpoint="<?= $e($sceneEndpoint) ?>" data-field="location" data-version="<?= (int) $scene['lock_version'] ?>"></label>
            <label>Time of day <input value="<?= $e($scene['time_of_day']) ?>" placeholder="Night" data-autosave data-endpoint="<?= $e($sceneEndpoint) ?>" data-field="time_of_day" data-version="<?= (int) $scene['lock_version'] ?>"></label>
            <label>Episode <select data-autosave data-endpoint="<?= $e($sceneEndpoint) ?>" data-field="episode_uuid" data-version="<?= (int) $scene['lock_version'] ?>"><option value="">No episode</option><?php foreach ($episodes as $episode): ?><option value="<?= $e($episode['uuid']) ?>" <?= (int) $scene['episode_id'] === (int) $episode['id'] ? 'selected' : '' ?>><?= $e($episode['title']) ?></option><?php endforeach; ?></select></label>
            <label>Act <select data-autosave data-endpoint="<?= $e($sceneEndpoint) ?>" data-field="act_uuid" data-version="<?= (int) $scene['lock_version'] ?>"><option value="">No act</option><?php foreach ($acts as $act): ?><option value="<?= $e($act['uuid']) ?>" <?= (int) $scene['act_id'] === (int) $act['id'] ? 'selected' : '' ?>><?= $e($act['title']) ?></option><?php endforeach; ?></select></label>
            <label>Summary <textarea rows="5" placeholder="What happens in this scene?" data-autosave data-endpoint="<?= $e($sceneEndpoint) ?>" data-field="summary" data-version="<?= (int) $scene['lock_version'] ?>"><?= $e($scene['summary']) ?></textarea></label>
            <form class="delete-scene" method="post" action="/projects/<?= $projectUuid ?>/items/scene/<?= $sceneUuid ?>/delete" data-confirm="Delete this scene and every block it contains?"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><button class="danger" type="submit">Delete scene</button></form>
        </aside>
        <div class="script-editor" id="blocks">
            <div class="script-page-heading"><span>SCENE</span><h1><?= $e($scene['heading']) ?></h1><p>Use the block controls to keep screenplay structure intact.</p></div>
            <?php if (!$blocks): ?><div class="empty-script"><h2>Begin the scene</h2><p>Add an action or character cue. Every item stays independently editable and ordered.</p></div><?php endif; ?>
            <?php foreach ($blocks as $block): $blockEndpoint = '/api/projects/' . $projectUuid . '/blocks/' . $e($block['uuid']); ?>
                <article class="block block-<?= $e($block['block_type']) ?>" data-block data-block-type="<?= $e($block['block_type']) ?>">
                    <div class="block-tools"><span><?= $e(ucfirst($block['block_type'])) ?></span><form method="post" action="/projects/<?= $projectUuid ?>/scenes/<?= $sceneUuid ?>/blocks/<?= $e($block['uuid']) ?>/move"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><button name="direction" value="up" title="Move block up">↑</button><button name="direction" value="down" title="Move block down">↓</button></form><form method="post" action="/projects/<?= $projectUuid ?>/scenes/<?= $sceneUuid ?>/blocks/<?= $e($block['uuid']) ?>/delete" data-confirm="Delete this content block?"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><button class="icon-danger" title="Delete block">×</button></form></div>
                    <div class="block-fields"><select class="block-type-select" aria-label="Block type" data-autosave data-endpoint="<?= $e($blockEndpoint) ?>" data-field="block_type" data-version="<?= (int) $block['lock_version'] ?>"><?php foreach (['action', 'character', 'dialogue', 'parenthetical', 'transition', 'note'] as $type): ?><option value="<?= $e($type) ?>" <?= $block['block_type'] === $type ? 'selected' : '' ?>><?= $e(ucfirst($type)) ?></option><?php endforeach; ?></select>
                        <label class="speaker-field <?= $block['block_type'] === 'dialogue' ? '' : 'hidden' ?>">Speaker <select data-autosave data-endpoint="<?= $e($blockEndpoint) ?>" data-field="speaker_uuid" data-version="<?= (int) $block['lock_version'] ?>"><option value="">Choose character</option><?php foreach ($characters as $character): ?><option value="<?= $e($character['uuid']) ?>" <?= $block['speaker_character_id'] && (int) $block['speaker_character_id'] === (int) $character['id'] ? 'selected' : '' ?>><?= $e($character['name']) ?></option><?php endforeach; ?></select></label>
                        <textarea aria-label="<?= $e(ucfirst($block['block_type'])) ?> text" rows="<?= $block['block_type'] === 'dialogue' ? 3 : 2 ?>" data-autosave data-endpoint="<?= $e($blockEndpoint) ?>" data-field="content" data-version="<?= (int) $block['lock_version'] ?>"><?= $e($block['content']) ?></textarea>
                    </div>
                </article>
            <?php endforeach; ?>
            <form class="add-block" method="post" action="/projects/<?= $projectUuid ?>/scenes/<?= $sceneUuid ?>/blocks"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><label>Add screenplay block <select name="block_type"><option value="action">Action</option><option value="character">Character cue</option><option value="dialogue">Dialogue</option><option value="parenthetical">Parenthetical</option><option value="transition">Transition</option><option value="note">Note</option></select></label><button class="primary" type="submit">Add block</button></form>
        </div>
    </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
