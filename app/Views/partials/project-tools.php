<?php $toolsPath = '/projects/' . $e($project['uuid']); ?>
<nav class="project-tools" aria-label="Project tools">
    <a href="<?= $toolsPath ?>/search">Search</a>
    <a href="<?= $toolsPath ?>/characters">Characters</a>
    <a href="<?= $toolsPath ?>/plots">Plot threads</a>
    <a href="<?= $toolsPath ?>/export">JSON backup</a>
    <a href="<?= $toolsPath ?>/pdf">Export PDF</a>
</nav>
