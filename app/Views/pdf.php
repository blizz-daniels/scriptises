<?php
require __DIR__ . '/partials/header.php';
$path = '/projects/' . $e($project['uuid']);
$csrf = $e(\App\Support\Csrf::token());
$scopes = \App\Services\PdfExport::SCOPES;
$searchScope = in_array($options['scope'], ['selected', 'filtered'], true);
$sceneOptions = [];
foreach ($outline as $index => $scene) {
    $parts = array_filter([$scene['episode'], $scene['act'], $scene['heading'] ?: 'Untitled scene']);
    $sceneOptions[$scene['uuid']] = ($index + 1) . '. ' . implode(' / ', $parts);
}
$select = static function (string $name, array $choices, string $placeholder) use ($e, $options): void {
    ?>
    <select name="<?= $e($name) ?>">
        <option value=""><?= $e($placeholder) ?></option>
        <?php foreach ($choices as $id => $label): ?>
            <option value="<?= $e($id) ?>" <?= $id === $options[$name] ? 'selected' : '' ?>><?= $e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <?php
};
?>
<section class="page management-page">
    <a class="back-link" href="<?= $path ?>">‹ <?= $e($project['title']) ?></a>
    <h1>Export PDF</h1>
    <?php require __DIR__ . '/partials/project-tools.php'; ?>
    <div class="pdf-export-grid">
        <section class="panel pdf-options">
            <h2>Choose content</h2>
            <form action="<?= $path ?>/pdf#pdf-preview" method="post" class="form-stack" data-pdf-options>
                <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                <?php foreach ($options['filters'] as $field => $value): ?>
                    <input type="hidden" name="filters[<?= $e($field) ?>]" value="<?= $e($value) ?>">
                <?php endforeach; ?>
                <?php foreach ($options['blocks'] as $block): ?>
                    <input type="hidden" name="blocks[]" value="<?= $e($block) ?>">
                <?php endforeach; ?>
                <label>Content
                    <select name="scope" data-pdf-scope>
                        <?php foreach ($scopes as $scope => $name): ?>
                            <?php if (in_array($scope, ['selected', 'filtered'], true) && $scope !== $options['scope']) continue; ?>
                            <option value="<?= $e($scope) ?>" <?= $scope === $options['scope'] ? 'selected' : '' ?>><?= $e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="form-stack compact" data-pdf-scopes="character">
                    <label>Character <?php $select('character', array_column($characters, 'name', 'uuid'), 'Choose a character'); ?></label>
                </div>
                <div class="form-stack compact" data-pdf-scopes="episode character">
                    <label>Episode <?php $select('episode', array_column($episodes, 'title', 'uuid'), 'Choose an episode, or all for dialogue'); ?></label>
                </div>
                <div class="form-stack compact" data-pdf-scopes="act character">
                    <label>Act <?php $select('act', array_column($acts, 'title', 'uuid'), 'Choose an act, or all for dialogue'); ?></label>
                </div>
                <div class="form-stack compact" data-pdf-scopes="plot">
                    <label>Plot thread <?php $select('plot', array_column($plots, 'title', 'uuid'), 'Choose a thread'); ?></label>
                </div>
                <div class="form-stack compact" data-pdf-scopes="range">
                    <label>First scene <?php $select('from', $sceneOptions, 'Choose the first scene'); ?></label>
                    <label>Through <?php $select('through', $sceneOptions, 'Choose the last scene'); ?></label>
                </div>
                <div class="form-stack compact" data-pdf-scopes="scenes selected character">
                    <fieldset class="pdf-scene-options">
                        <legend>Scenes</legend>
                        <div class="pdf-scene-list">
                            <?php foreach ($sceneOptions as $id => $name): ?>
                                <label class="selection-check">
                                    <input type="checkbox" name="scenes[]" value="<?= $e($id) ?>" <?= in_array($id, $options['scenes'], true) ? 'checked' : '' ?>>
                                    <?= $e($name) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <small class="muted">For character dialogue, leave unchecked to include all matching scenes.</small>
                    </fieldset>
                </div>
                <?php if ($searchScope): ?><p class="muted" data-pdf-scopes="filtered selected">This scope uses the filters or items you chose in search.</p><?php endif; ?>
                <label class="selection-check" data-pdf-scopes="full episode act scenes plot range filtered selected"><input type="checkbox" name="include_notes" value="1" <?= $options['include_notes'] ? 'checked' : '' ?>> Include writing notes</label>
                <button class="primary" type="submit">Preview PDF</button>
            </form>
        </section>
        <section class="pdf-preview" id="pdf-preview" aria-labelledby="pdf-preview-title">
            <div class="results-header">
                <div><h2 id="pdf-preview-title">Preview</h2><?php if ($previewSummary): ?><p class="muted"><?= $e($previewSummary) ?></p><?php endif; ?></div>
                <?php if ($previewToken): ?>
                    <form action="<?= $path ?>/pdf/download" method="post">
                        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="preview" value="<?= $e($previewToken) ?>">
                        <button class="primary" type="submit">Download PDF</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php if ($previewError): ?>
                <p class="panel flash-error" role="alert"><?= $e($previewError) ?></p>
            <?php elseif ($previewHtml !== null): ?>
                <div class="pdf-paper"><?= $previewHtml ?></div>
            <?php else: ?>
                <p class="panel muted">Choose what to export and preview it here before downloading.</p>
            <?php endif; ?>
        </section>
    </div>
</section>
<script src="/assets/pdf-options.js" defer></script>
<?php require __DIR__ . '/partials/footer.php'; ?>
