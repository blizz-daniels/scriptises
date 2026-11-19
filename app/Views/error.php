<?php require __DIR__ . '/partials/header.php'; $e = static fn (mixed $value): string => \App\Support\View::escape((string) $value); ?>
<section class="page error-page"><p class="eyebrow">Scriptises</p><h1><?= $e($pageTitle) ?></h1><p><?= $e($message) ?></p><a class="button primary" href="<?= !empty($user) ? '/' : '/login' ?>">Continue</a></section>
<?php require __DIR__ . '/partials/footer.php'; ?>
