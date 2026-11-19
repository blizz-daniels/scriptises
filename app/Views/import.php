<?php
require __DIR__ . '/partials/header.php';
$csrf = $e(\App\Support\Csrf::token());
?>
<section class="page management-page">
    <a class="back-link" href="/">‹ Projects</a>
    <h1>Import screenplay</h1>
    <?php if (!$pending): ?>
        <div class="import-columns">
            <section class="panel">
                <h2>Paste screenplay text</h2>
                <form method="post" action="/import/stage" class="form-stack">
                    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="format" value="paste">
                    <label>Project title <input name="title" required maxlength="255" value="<?= $e($pasteTitle ?? '') ?>"></label>
                    <label>Screenplay text <textarea class="writing-text" name="screenplay" rows="12" required placeholder="INT. STUDY - NIGHT"><?= $e($pasteText ?? '') ?></textarea></label>
                    <button class="primary">Review pasted text</button>
                </form>
            </section>
            <section class="panel">
                <h2>Upload a screenplay file</h2>
                <form method="post" action="/import/stage" enctype="multipart/form-data" class="form-stack">
                    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="format" value="file">
                    <label>Word (.docx), Fountain (.fountain), or JSON
                        <input type="file" name="backup" accept=".docx,.fountain,.json" required>
                    </label>
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
                <form class="panel form-stack" action="/import/confirm" method="post" enctype="multipart/form-data" data-import-review>
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
                    <label>Import destination
                        <select name="destination" required>
                            <option value="new">Create a new project</option>
                            <?php foreach ($projects as $destination): ?>
                                <option value="<?= $e($destination['uuid']) ?>">Add to <?= $e($destination['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <p class="muted">Adding to a project keeps its existing screenplay. Characters with matching names are reused.</p>
                    <button class="primary" type="submit">Confirm import</button>
                </form>
            </div>
            <script src="/assets/import-review.js" defer></script>
        </section>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
