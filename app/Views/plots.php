<?php
require __DIR__ . '/partials/header.php';
$path = '/projects/' . $e($project['uuid']);
$csrf = $e(\App\Support\Csrf::token());
$options = ['scenes' => [$scenes, 'heading'], 'characters' => [$characters, 'name'], 'acts' => [$acts, 'title'], 'episodes' => [$episodes, 'title']];
?>
<section class="page management-page">
    <a class="back-link" href="<?= $path ?>">‹ <?= $e($project['title']) ?></a>
    <h1>Plot threads</h1>
    <?php require __DIR__ . '/partials/project-tools.php'; ?>
    <div class="management-columns">
        <aside class="panel">
            <h2>Threads</h2>
            <div class="thread-list">
                <?php foreach ($plots as $thread): ?>
                    <a href="<?= $path ?>/plots?thread=<?= $e($thread['uuid']) ?>" <?= ($plot['uuid'] ?? '') === $thread['uuid'] ? 'aria-current="page"' : '' ?>><?= $e($thread['title']) ?></a>
                <?php endforeach; ?>
            </div>
            <a class="button small" href="<?= $path ?>/plots">New thread</a>
        </aside>
        <div>
            <section class="panel">
                <h2><?= $e($plot['title'] ?? 'New plot thread') ?></h2>
                <form class="form-stack" method="post" action="<?= $path ?>/plots">
                    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="plot" value="<?= $e($plot['uuid'] ?? '') ?>">
                    <label>Title <input name="title" maxlength="255" required value="<?= $e($plot['title'] ?? '') ?>"></label>
                    <label>Description <textarea name="description" rows="4"><?= $e($plot['description'] ?? '') ?></textarea></label>
                    <?php foreach ($options as $kind => [$rows, $label]): ?>
                        <?php if (!$rows) continue; ?>
                        <details class="link-options">
                            <summary><?= $e(ucfirst($kind)) ?> (<?= count($plot[$kind] ?? []) ?> linked)</summary>
                            <?php foreach ($rows as $row): ?>
                                <label class="selection-check"><input type="checkbox" name="<?= $e($kind) ?>[]" value="<?= $e($row['uuid']) ?>" <?= in_array($row['uuid'], $plot[$kind] ?? [], true) ? 'checked' : '' ?>> <?= $e($row[$label]) ?></label>
                            <?php endforeach; ?>
                        </details>
                    <?php endforeach; ?>
                    <button class="primary" type="submit">Save thread</button>
                </form>
                <?php if ($plot): ?>
                    <form class="revision-panel" method="post" action="<?= $path ?>/plots/<?= $e($plot['uuid']) ?>/delete" data-confirm="Delete this plot thread?">
                        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                        <button class="danger small" type="submit">Delete thread</button>
                    </form>
                <?php endif; ?>
            </section>
            <?php if ($linked): ?>
                <section class="panel revision-panel">
                    <h2>Related scenes</h2>
                    <ol class="thread-list">
                        <?php foreach ($linked as $row): ?>
                            <li><a href="<?= $path ?>/scenes/<?= $e($row['scene_uuid']) ?>"><?= $e($row['heading']) ?></a><?php if ($row['episode'] || $row['act']): ?> <span class="muted"><?= $e(implode(' · ', array_filter([$row['episode'], $row['act']]))) ?></span><?php endif; ?></li>
                        <?php endforeach; ?>
                    </ol>
                </section>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
