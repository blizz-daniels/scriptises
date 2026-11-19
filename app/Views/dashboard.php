<?php
require __DIR__ . '/partials/header.php';
$archived = array_filter($archivedProjects, static fn (array $project): bool => $project['status'] === 'archived');
$projectTypes = [
    'film' => 'Feature film',
    'short_film' => 'Short film',
    'limited_series' => 'Limited series',
    'tv_series' => 'Television series',
    'other' => 'Other',
];
?>
<section class="page dashboard">
    <section class="desk-hero" aria-labelledby="desk-title">
        <div class="desk-hero-copy">
            <h1 id="desk-title">The writing desk</h1>
            <p>A place for your screenplays, from first scene to final draft.</p>
            <div class="page-actions">
                <a class="button primary" href="#new-project"><span aria-hidden="true">+</span> New project</a>
                <a class="button" href="/import">Import screenplay</a>
            </div>
        </div>
    </section>

    <div class="desk-layout">
        <section class="project-library" aria-labelledby="library-title">
            <div class="library-heading">
                <h2 id="library-title">Your screenplays</h2>
                <span class="library-count"><?= count($projects) ?> <?= count($projects) === 1 ? 'project' : 'projects' ?></span>
            </div>

            <?php if ($projects): ?>
                <div class="project-grid">
                    <?php foreach ($projects as $project): ?>
                        <a class="project-card" href="/projects/<?= $e($project['uuid']) ?>">
                            <span class="book-mark" aria-hidden="true"></span>
                            <div class="project-card-copy">
                                <span class="project-type"><?= $e($projectTypes[$project['project_type']] ?? $project['project_type']) ?></span>
                                <h3><?= $e($project['title']) ?></h3>
                                <?php if ($project['description']): ?><p><?= $e($project['description']) ?></p><?php endif; ?>
                            </div>
                            <div class="project-meta">
                                <span><?= (int) $project['scene_count'] ?> <?= (int) $project['scene_count'] === 1 ? 'scene' : 'scenes' ?></span>
                                <time datetime="<?= $e(date('Y-m-d', strtotime($project['updated_at']))) ?>"><?= $e(date('M j, Y', strtotime($project['updated_at']))) ?></time>
                            </div>
                            <span class="project-chevron" aria-hidden="true">›</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <span class="book-mark" aria-hidden="true"></span>
                    <h3>Your first scene is waiting</h3>
                    <p>Create a screenplay to start writing.</p>
                </div>
            <?php endif; ?>

            <?php if ($archived): ?>
                <details class="panel archive-list">
                    <summary>Archived projects (<?= count($archived) ?>)</summary>
                    <?php foreach ($archived as $project): ?>
                        <a href="/projects/<?= $e($project['uuid']) ?>"><?= $e($project['title']) ?></a>
                    <?php endforeach; ?>
                </details>
            <?php endif; ?>
        </section>

        <section class="panel new-project" id="new-project" aria-labelledby="new-project-title">
            <h2 id="new-project-title">New screenplay</h2>
            <form action="/projects" method="post" class="form-stack">
                <input type="hidden" name="_csrf" value="<?= $e(\App\Support\Csrf::token()) ?>">
                <label>Title <input name="title" required maxlength="255" placeholder="Untitled Screenplay"></label>
                <label>Type
                    <select name="project_type">
                        <?php foreach ($projectTypes as $type => $label): ?>
                            <option value="<?= $e($type) ?>"><?= $e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Description <span class="optional">Optional</span>
                    <textarea name="description" rows="3" placeholder="A few words about the story"></textarea>
                </label>
                <button class="primary" type="submit">Create project</button>
            </form>
        </section>
    </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
