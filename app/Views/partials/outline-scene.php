<div class="tree-scene">
    <a href="/projects/<?= $e($project['uuid']) ?>/scenes/<?= $e($scene['uuid']) ?>"><?= $e($scene['heading']) ?></a>
    <form method="post" action="/projects/<?= $e($project['uuid']) ?>/items/scene/<?= $e($scene['uuid']) ?>/move">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <button name="direction" value="up" aria-label="Move scene up">↑</button>
        <button name="direction" value="down" aria-label="Move scene down">↓</button>
    </form>
</div>
