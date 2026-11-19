<?php
require __DIR__ . '/partials/header.php';
$path = '/projects/' . $e($project['uuid']);
$csrf = $e(\App\Support\Csrf::token());
?>
<section class="page management-page history-page">
    <a class="back-link" href="<?= $path ?>/scenes/<?= $e($scene) ?>#block-<?= $e($block['uuid']) ?>">‹ Return to scene</a>
    <h1>Writing history</h1>
    <div class="panel"><h2>Current text</h2><pre class="writing-text"><?= $e($block['content']) ?></pre></div>
    <?php if (!$history): ?><p class="muted revision-panel">Earlier edits will appear here as you save changes.</p><?php endif; ?>
    <?php foreach ($history as $entry): ?>
        <details class="panel revision-panel">
            <summary><?= $e($entry['label']) ?> · <?= $e($entry['created_at']) ?> UTC</summary>
            <pre class="writing-text"><?= $e($entry['text']) ?></pre>
            <form action="<?= $path ?>/blocks/<?= $e($block['uuid']) ?>/history" method="post" data-confirm="Restore this text to the current block?">
                <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                <input type="hidden" name="revision" value="<?= (int) $entry['id'] ?>">
                <input type="hidden" name="version" value="<?= (int) $block['lock_version'] ?>">
                <button class="button small" type="submit">Restore this text</button>
            </form>
        </details>
    <?php endforeach; ?>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
