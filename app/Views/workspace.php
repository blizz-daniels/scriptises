<?php
require __DIR__ . '/partials/header.php';
$e = static fn (mixed $value): string => \App\Support\View::escape((string) $value);
$csrf = $e(\App\Support\Csrf::token());
$actsByEpisode = [];
foreach ($acts as $act) $actsByEpisode[$act['episode_id'] ?? 'none'][] = $act;
$scenesByScope = [];
foreach ($scenes as $scene) $scenesByScope[(string) ($scene['act_id'] ?? 'none')][] = $scene;
$scopeOptions = static function (array $episodes, array $acts, ?array $scene = null) use ($e): string {
    $selectedEpisode = $scene['episode_id'] ?? null; $selectedAct = $scene['act_id'] ?? null;
    $html = '<option value="">No episode</option>';
    foreach ($episodes as $episode) $html .= '<option value="' . $e($episode['uuid']) . '"' . ((int) $selectedEpisode === (int) $episode['id'] ? ' selected' : '') . '>' . $e($episode['title']) . '</option>';
    $html .= '</select><select name="act_uuid"><option value="">No act</option>';
    foreach ($acts as $act) $html .= '<option value="' . $e($act['uuid']) . '"' . ((int) $selectedAct === (int) $act['id'] ? ' selected' : '') . '>' . $e($act['title']) . '</option>';
    return $html . '</select>';
};
?>
<section class="workspace">
    <aside class="workspace-sidebar">
        <div class="sidebar-project"><a href="/">‹ Projects</a><h1><?= $e($project['title']) ?></h1><p><?= $e(str_replace('_', ' ', $project['project_type'])) ?></p></div>
        <div class="sidebar-actions"><a class="button small primary" href="#new-scene">Add scene</a><a class="button small" href="/projects/<?= $e($project['uuid']) ?>/read">Read screenplay</a></div>
        <nav class="structure-nav" aria-label="Screenplay structure">
            <p class="nav-label">Structure</p>
            <?php foreach ($episodes as $episode): ?>
                <details open class="tree-group"><summary><span>Episode</span> <?= $e($episode['title']) ?></summary>
                    <div class="tree-manage">
                        <form method="post" action="/projects/<?= $e($project['uuid']) ?>/items/episode/<?= $e($episode['uuid']) ?>/rename"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input name="title" value="<?= $e($episode['title']) ?>" aria-label="Episode name"><button title="Rename">Save</button></form>
                        <form method="post" action="/projects/<?= $e($project['uuid']) ?>/items/episode/<?= $e($episode['uuid']) ?>/move"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><button name="direction" value="up" title="Move episode up">↑</button><button name="direction" value="down" title="Move episode down">↓</button></form>
                    </div>
                    <?php foreach ($actsByEpisode[$episode['id']] ?? [] as $act): ?>
                        <div class="tree-act"><div class="tree-line"><span>Act</span><strong><?= $e($act['title']) ?></strong></div>
                            <div class="tree-manage"><form method="post" action="/projects/<?= $e($project['uuid']) ?>/items/act/<?= $e($act['uuid']) ?>/rename"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input name="title" value="<?= $e($act['title']) ?>" aria-label="Act name"><button>Save</button></form><form method="post" action="/projects/<?= $e($project['uuid']) ?>/items/act/<?= $e($act['uuid']) ?>/move"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><button name="direction" value="up">↑</button><button name="direction" value="down">↓</button></form></div>
                            <?php foreach ($scenesByScope[$act['id']] ?? [] as $scene): ?><div class="tree-scene"><a href="/projects/<?= $e($project['uuid']) ?>/scenes/<?= $e($scene['uuid']) ?>"><?= $e($scene['heading']) ?></a><form method="post" action="/projects/<?= $e($project['uuid']) ?>/items/scene/<?= $e($scene['uuid']) ?>/move"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><button name="direction" value="up">↑</button><button name="direction" value="down">↓</button></form></div><?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </details>
            <?php endforeach; ?>
            <?php foreach ($actsByEpisode['none'] ?? [] as $act): ?>
                <details open class="tree-group"><summary><span>Act</span> <?= $e($act['title']) ?></summary>
                    <div class="tree-manage"><form method="post" action="/projects/<?= $e($project['uuid']) ?>/items/act/<?= $e($act['uuid']) ?>/rename"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input name="title" value="<?= $e($act['title']) ?>"><button>Save</button></form><form method="post" action="/projects/<?= $e($project['uuid']) ?>/items/act/<?= $e($act['uuid']) ?>/move"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><button name="direction" value="up">↑</button><button name="direction" value="down">↓</button></form></div>
                    <?php foreach ($scenesByScope[$act['id']] ?? [] as $scene): ?><div class="tree-scene"><a href="/projects/<?= $e($project['uuid']) ?>/scenes/<?= $e($scene['uuid']) ?>"><?= $e($scene['heading']) ?></a><form method="post" action="/projects/<?= $e($project['uuid']) ?>/items/scene/<?= $e($scene['uuid']) ?>/move"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><button name="direction" value="up">↑</button><button name="direction" value="down">↓</button></form></div><?php endforeach; ?>
                </details>
            <?php endforeach; ?>
            <?php foreach ($scenesByScope['none'] ?? [] as $scene): ?><div class="tree-scene root-scene"><a href="/projects/<?= $e($project['uuid']) ?>/scenes/<?= $e($scene['uuid']) ?>"><?= $e($scene['heading']) ?></a><form method="post" action="/projects/<?= $e($project['uuid']) ?>/items/scene/<?= $e($scene['uuid']) ?>/move"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><button name="direction" value="up">↑</button><button name="direction" value="down">↓</button></form></div><?php endforeach; ?>
        </nav>
    </aside>
    <div class="workspace-main">
        <div class="workspace-heading"><div><p class="eyebrow">Native screenplay workspace</p><h2><?= $e($project['title']) ?></h2><p class="muted"><?= $e($project['description'] ?: 'Organize scenes, then write in focused screenplay blocks.') ?></p></div><a class="button" href="/projects/<?= $e($project['uuid']) ?>/read">Full screenplay</a></div>
        <div class="workspace-grid">
            <section class="panel" id="new-scene"><h3>Add a scene</h3><form action="/projects/<?= $e($project['uuid']) ?>/scenes" method="post" class="form-stack compact"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><label>Scene heading <input name="heading" placeholder="INT. APARTMENT - NIGHT" required></label><label>Episode <select name="episode_uuid"><option value="">No episode</option><?php foreach ($episodes as $episode): ?><option value="<?= $e($episode['uuid']) ?>"><?= $e($episode['title']) ?></option><?php endforeach; ?></select></label><label>Act <select name="act_uuid"><option value="">No act</option><?php foreach ($acts as $act): ?><option value="<?= $e($act['uuid']) ?>"><?= $e($act['title']) ?></option><?php endforeach; ?></select></label><button class="primary" type="submit">Create and write</button></form></section>
            <section class="panel"><h3>Structure</h3><div class="stacked-forms"><form action="/projects/<?= $e($project['uuid']) ?>/episodes" method="post"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><label>New episode <input name="title" placeholder="Episode 1" required></label><button type="submit">Add episode</button></form><form action="/projects/<?= $e($project['uuid']) ?>/acts" method="post"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><label>New act <input name="title" placeholder="Act 1" required></label><label>Episode <select name="episode_uuid"><option value="">Standalone act</option><?php foreach ($episodes as $episode): ?><option value="<?= $e($episode['uuid']) ?>"><?= $e($episode['title']) ?></option><?php endforeach; ?></select></label><button type="submit">Add act</button></form></div></section>
            <section class="panel"><h3>Characters</h3><form action="/projects/<?= $e($project['uuid']) ?>/characters" method="post" class="form-stack compact"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><label>Name <input name="name" placeholder="CHARACTER NAME" required></label><label>Description <textarea name="description" rows="2" placeholder="Optional notes"></textarea></label><button type="submit">Add character</button></form><div class="character-list"><?php foreach ($characters as $character): ?><div><form method="post" action="/projects/<?= $e($project['uuid']) ?>/items/character/<?= $e($character['uuid']) ?>/rename"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input name="title" value="<?= $e($character['name']) ?>"><button>Save</button></form></div><?php endforeach; ?></div></section>
        </div>
        <section class="panel revision-panel"><details><summary>Recent revision history</summary><?php if ($recentRevisions): ?><ol class="revision-list"><?php foreach ($recentRevisions as $revision): ?><li><strong><?= $e(ucfirst($revision['entity_type'])) ?></strong> <?= $e($revision['action']) ?><time><?= $e(date('M j, H:i', strtotime($revision['created_at']))) ?></time></li><?php endforeach; ?></ol><?php else: ?><p class="muted">Changes to this project will appear here.</p><?php endif; ?></details></section>
        <section class="panel project-settings"><details><summary>Project settings</summary><div class="settings-grid"><form action="/projects/<?= $e($project['uuid']) ?>" method="post" class="form-stack"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><label>Title <input name="title" value="<?= $e($project['title']) ?>" required></label><label>Type <select name="project_type"><?php foreach (['film' => 'Feature film', 'short_film' => 'Short film', 'limited_series' => 'Limited series', 'tv_series' => 'Television series', 'other' => 'Other'] as $value => $label): ?><option value="<?= $e($value) ?>" <?= $project['project_type'] === $value ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label><label>Description <textarea name="description" rows="3"><?= $e($project['description']) ?></textarea></label><button type="submit">Save details</button></form><div class="danger-zone"><h4>Project status</h4><form method="post" action="/projects/<?= $e($project['uuid']) ?>/archive"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="status" value="<?= $project['status'] === 'active' ? 'archived' : 'active' ?>"><button type="submit"><?= $project['status'] === 'active' ? 'Archive project' : 'Restore project' ?></button></form><form method="post" action="/projects/<?= $e($project['uuid']) ?>/delete" data-confirm="This permanently deletes all scenes and revisions. Continue?"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><label>Type <strong><?= $e($project['title']) ?></strong> to delete <input name="confirmation" required></label><button class="danger" type="submit">Delete project</button></form></div></div></details></section>
    </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
