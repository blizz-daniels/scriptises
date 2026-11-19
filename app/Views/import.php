<?php
require __DIR__ . '/partials/header.php';
$csrf = $e(\App\Support\Csrf::token());
$project = $catalog['project'] ?? null;
?>
<section class="page management-page">
    <?php if ($project): ?>
        <a class="back-link" href="/projects/<?= $e($project['uuid']) ?>">‹ <?= $e($project['title']) ?></a>
        <h1>Import act or scene</h1>
    <?php else: ?>
        <a class="back-link" href="/">‹ Projects</a>
        <h1>Import screenplay</h1>
    <?php endif; ?>
    <?php if (!$pending): ?>
        <div class="import-columns">
            <?php if (!$project): ?><section class="panel">
                <h2>Paste screenplay text</h2>
                <form method="post" action="/import/stage" class="form-stack">
                    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="format" value="paste">
                    <label>Project title <input name="title" required maxlength="255" value="<?= $e($pasteTitle ?? '') ?>"></label>
                    <label>Screenplay text <textarea class="writing-text" name="screenplay" rows="12" required placeholder="INT. STUDY - NIGHT"><?= $e($pasteText ?? '') ?></textarea></label>
                    <button class="primary">Review pasted text</button>
                </form>
            </section><?php endif; ?>
            <section class="panel">
                <h2><?= $project ? 'Upload an act or scene' : 'Upload a screenplay file' ?></h2>
                <form method="post" action="/import/stage" enctype="multipart/form-data" class="form-stack">
                    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="format" value="<?= $project ? 'json' : 'file' ?>">
                    <?php if ($project): ?><input type="hidden" name="project" value="<?= $e($project['uuid']) ?>"><?php endif; ?>
                    <label><?= $project ? 'JSON file (.json)' : 'Word (.docx), Fountain (.fountain), or JSON' ?>
                        <input type="file" name="backup" accept="<?= $project ? '.json' : '.docx,.fountain,.json' ?>" required>
                    </label>
                    <?php if ($project): ?>
                        <p class="muted">Upload a scene with <code>heading</code> and <code>blocks</code>, or an act with <code>title</code> and <code>scenes</code>. You can also upload a Scriptises JSON export.</p>
                    <?php endif; ?>
                    <button class="primary">Review uploaded file</button>
                </form>
            </section>
        </div>
    <?php else: ?>
        <section id="review" aria-labelledby="review-title">
            <div class="results-header">
                <div><h2 id="review-title">Review before importing</h2><p class="muted">Check suggested headings, block types, and speakers against the original.</p></div>
                <form method="post" action="/import/cancel" data-confirm="Discard this import draft?">
                    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                    <button class="small">Discard draft</button>
                </form>
            </div>
            <div class="import-columns review-columns">
                <section class="panel">
                    <?php $sourcePreview = $pending['preview'] ?? $pending['source']; ?>
                    <h3><?= $pending['format'] === 'docx' ? 'Extracted Word text' : 'Original source' ?></h3>
                    <a class="back-link" href="/import/source">Download original</a>
                    <pre class="writing-text source-preview"><?= $e(mb_strcut($sourcePreview, 0, 80000)) ?></pre>
                    <?php if (strlen($sourcePreview) > 80000): ?><p class="muted">Preview shortened. Download the full original above.</p><?php endif; ?>
                </section>
                <form class="panel form-stack" action="/import/confirm" method="post" enctype="multipart/form-data" data-import-review <?= $project ? 'data-project-import' : '' ?>>
                    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="draft_token" value="<?= $e($pending['token']) ?>">
                    <h3>Proposed structure</h3>
                    <div data-review-editor hidden></div>
                    <details class="review-json" <?= !empty($pending['review']) ? 'open' : '' ?>>
                        <summary>Edit as JSON</summary>
                        <label>Proposed document <textarea class="writing-text" data-review-json name="proposal" rows="12"><?= "\n" . $e($pending['review'] ?? json_encode($pending['document'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></textarea></label>
                        <button type="button" class="small" data-apply-json hidden>Apply JSON to review</button>
                    </details>
                    <script type="application/json" data-review-flags><?= json_encode($pending['flags'], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
                    <p class="flash-error review-error" role="alert" data-review-error hidden></p>
                    <?php if ($project): ?>
                        <?php $episodeNames = array_column($catalog['episodes'], 'title', 'id'); ?>
                        <p>Adding to <strong><?= $e($project['title']) ?></strong></p>
                        <label>Place imported content in
                            <select name="scope">
                                <option value="">Project outline (keep the file’s structure)</option>
                                <?php foreach ($catalog['episodes'] as $episode): ?>
                                    <?php $scope = 'episode:' . $episode['uuid']; ?>
                                    <option value="<?= $e($scope) ?>" <?= ($pending['scope'] ?? '') === $scope ? 'selected' : '' ?>>Episode · <?= $e($episode['title']) ?></option>
                                <?php endforeach; ?>
                                <?php foreach ($catalog['acts'] as $act): ?>
                                    <?php $scope = 'act:' . $act['uuid']; ?>
                                    <option value="<?= $e($scope) ?>" <?= ($pending['scope'] ?? '') === $scope ? 'selected' : '' ?>>Act · <?= $e($act['title']) ?><?= $act['episode_id'] !== null ? ' (' . $e($episodeNames[$act['episode_id']]) . ')' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <p class="muted">Choose an existing act to append scenes to it. Choose an episode to add acts or scenes to that episode.</p>
                    <?php else: ?>
                    <label>Import destination
                        <select name="destination" required>
                            <option value="new">Create a new project</option>
                            <?php foreach ($projects as $destination): ?>
                                <option value="<?= $e($destination['uuid']) ?>">Add to <?= $e($destination['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php endif; ?>
                    <p class="muted">Adding to a project keeps its existing screenplay. Characters with matching names are reused.</p>
                    <button class="primary" type="submit">Confirm import</button>
                </form>
            </div>
            <script src="/assets/import-review.js" defer></script>
        </section>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
