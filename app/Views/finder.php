<?php
require __DIR__ . '/partials/header.php';
$path = '/projects/' . $e($project['uuid']);
$base = $path . ($characterView ? '/characters' : '/search');
$csrf = $e(\App\Support\Csrf::token());
$list = static function (string $name, array $options, string $any = 'Any') use ($e, $filters): void {
    ?>
    <select name="<?= $e($name) ?>">
        <option value=""><?= $e($any) ?></option>
        <?php foreach ($options as $id => $label): ?>
            <option value="<?= $e($id) ?>" <?= $id === $filters[$name] ? 'selected' : '' ?>><?= $e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <?php
};
$highlight = static function (string $text) use ($e, $filters): string {
    if ($filters['q'] === '') {
        return $e($text);
    }
    $parts = preg_split('/(' . preg_quote($filters['q'], '/') . ')/iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        return $e($text);
    }
    return implode('', array_map(static fn ($index, $part) => $index % 2 ? '<mark>' . $e($part) . '</mark>' : $e($part), array_keys($parts), $parts));
};
?>
<section class="page management-page">
    <a class="back-link" href="<?= $path ?>">‹ <?= $e($project['title']) ?></a>
    <h1><?= $e($characterView ? ($profile['name'] ?? 'Character dialogue') : 'Search screenplay') ?></h1>
    <?php require __DIR__ . '/partials/project-tools.php'; ?>

    <?php if ($profile): ?>
        <details class="panel profile-panel">
            <summary>Character profile<?php if ($profile['aliases']): ?> · <?= $e(implode(', ', $profile['aliases'])) ?><?php endif; ?></summary>
            <?php if ($profile['description']): ?><p class="muted"><?= nl2br($e($profile['description'])) ?></p><?php endif; ?>
            <form action="<?= $path ?>/characters/<?= $e($profile['uuid']) ?>" method="post" class="form-stack">
                <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                <label>Name <input name="name" required maxlength="255" value="<?= $e($profile['name']) ?>"></label>
                <label>Biography <textarea name="description" rows="3"><?= $e($profile['description']) ?></textarea></label>
                <label>Aliases, one per line <textarea name="aliases" rows="2"><?= $e(implode("\n", $profile['aliases'])) ?></textarea></label>
                <button type="submit" class="primary">Save profile</button>
            </form>
        </details>
    <?php elseif ($characterView): ?>
        <p class="muted">Add a character in the <a href="<?= $path ?>#characters">project workspace</a> to track their dialogue.</p>
    <?php endif; ?>

    <form class="panel filter-grid" method="get" action="<?= $base ?>">
        <label>Words or phrase <input type="search" name="q" maxlength="255" value="<?= $e($filters['q']) ?>" placeholder="Search text and headings"></label>
        <label>Location <input name="location" maxlength="255" value="<?= $e($filters['location']) ?>"></label>
        <label>Results <?php $list('view', ['scenes' => 'Scenes'], 'Screenplay blocks'); ?></label>
        <label>Block type <?php $list('type', array_combine(\App\Services\ManagementService::BLOCK_TYPES, array_map('ucfirst', \App\Services\ManagementService::BLOCK_TYPES))); ?></label>
        <label>Episode <?php $list('episode', array_column($episodes, 'title', 'uuid')); ?></label>
        <label>Act <?php $list('act', array_column($acts, 'title', 'uuid')); ?></label>
        <label>Character <?php $list('character', array_column($characters, 'name', 'uuid')); ?></label>
        <label>Character relationship <?php $list('relation', ['dialogue' => 'Speaks', 'associated' => 'Explicitly associated', 'mentioned' => 'Mentioned in action'], 'Speaks or associated'); ?></label>
        <label>Plot thread <?php $list('plot', array_column($plots, 'title', 'uuid')); ?></label>
        <div class="page-actions">
            <button type="submit" class="primary">Apply filters</button>
            <a class="button" href="<?= $base ?>">Reset</a>
        </div>
    </form>

    <div class="results-header">
        <div><h2>Matches</h2><span class="save-status" data-save-status role="status">Saved</span></div>
        <?php if ($results): ?>
            <div class="page-actions">
                <button class="small" type="button" data-save-now>Save now</button>
                <a class="button small" href="<?= $path ?>/export?<?= $e(http_build_query(['scope' => 'filtered'] + $filters)) ?>">Export matches</a>
                <a class="button small" href="<?= $path ?>/pdf?<?= $e(http_build_query(['scope' => 'filtered', 'include_notes' => '1'] + $filters)) ?>#pdf-preview">Preview matches as PDF</a>
                <form id="selection-export" action="<?= $path ?>/export" method="get">
                    <input type="hidden" name="scope" value="selected">
                    <input type="hidden" name="include_notes" value="1">
                    <div class="page-actions">
                        <button class="small" type="submit">Export checked</button>
                        <button class="small" type="submit" formaction="<?= $path ?>/pdf#pdf-preview">Preview checked as PDF</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
    <?php if (!$results): ?><p class="muted">No matches for these filters.</p><?php endif; ?>
    <?php foreach ($results as $row):
        $block = $row['block_uuid'] ?? null;
        $scenePath = $path . '/scenes/' . $e($row['scene_uuid']);
        $api = '/api' . $path . '/' . ($block ? 'blocks/' . $e($block) : 'scenes/' . $e($row['scene_uuid']));
        $context = implode(' · ', array_filter([$row['episode'], $row['act'], $row['location']]));
        ?>
        <article class="panel result-entry">
            <div class="result-heading">
                <h3><?= $highlight($row['heading']) ?></h3>
                <label class="selection-check"><input type="checkbox" form="selection-export" name="<?= $block ? 'blocks' : 'scenes' ?>[]" value="<?= $e($block ?? $row['scene_uuid']) ?>"> Export</label>
            </div>
            <?php if ($context !== ''): ?><div class="muted"><?= $highlight($context) ?></div><?php endif; ?>
            <?php if ($block): ?>
                <p class="nav-label"><?= $e(ucfirst($row['block_type'])) ?><?= $row['speaker'] ? ' · ' . $highlight($row['speaker']) : '' ?></p>
                <?php if ($characterView && $row['previous_text']): ?><p class="muted previous-text">Previous block · <?= $e($row['previous_text']) ?></p><?php endif; ?>
                <?php if ($filters['q'] !== '' && mb_stripos($row['content'], $filters['q']) !== false): ?>
                    <?php $offset = max(0, mb_stripos($row['content'], $filters['q']) - 45); ?>
                    <p class="match-preview"><?= $offset ? '…' : '' ?><?= $highlight(mb_substr($row['content'], $offset, 345)) ?></p>
                <?php endif; ?>
                <label>Block text
                    <textarea rows="3" class="writing-text" data-autosave data-endpoint="<?= $api ?>" data-field="content" data-version="<?= (int) $row['block_version'] ?>"><?= $e($row['content']) ?></textarea>
                </label>
            <?php else: ?>
                <label>Scene heading
                    <input maxlength="500" data-autosave data-endpoint="<?= $api ?>" data-field="heading" data-version="<?= (int) $row['scene_version'] ?>" value="<?= $e($row['heading']) ?>">
                </label>
            <?php endif; ?>
            <div class="page-actions">
                <a class="edit-scene-link" href="<?= $scenePath ?><?= $block ? '#block-' . $e($block) : '' ?>">Open scene</a>
                <?php if ($block): ?><a class="edit-scene-link" href="<?= $path ?>/blocks/<?= $e($block) ?>/history">Writing history</a><?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
    <?php if ($filters['page'] > 1 || $hasNext): ?>
        <nav class="page-actions pagination" aria-label="Search result pages">
            <?php if ($filters['page'] > 1): ?><a class="button" href="<?= $base ?>?<?= $e(http_build_query(['page' => $filters['page'] - 1] + $filters)) ?>">Previous</a><?php endif; ?>
            <span>Page <?= (int) $filters['page'] ?></span>
            <?php if ($hasNext): ?><a class="button" href="<?= $base ?>?<?= $e(http_build_query(['page' => $filters['page'] + 1] + $filters)) ?>">Next</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
