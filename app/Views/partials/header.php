<?php
/** @var string $pageTitle */
$e = static fn (mixed $value): string => \App\Support\View::escape((string) $value);
$flash = \App\Support\Flash::pull();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= $e(\App\Support\Csrf::token()) ?>">
    <title><?= $e($pageTitle ?? 'Scriptises') ?> · Scriptises</title>
    <script src="/assets/theme.js"></script>
    <link rel="stylesheet" href="/assets/app.css">
    <?php if (!empty($pageStylesheet)): ?><link rel="stylesheet" href="<?= $e($pageStylesheet) ?>"><?php endif; ?>
</head>
<body>
<a class="skip-link" href="#main-content">Skip to content</a>
<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="/"><span class="brand-mark" aria-hidden="true">S</span>Scriptises</a>
        <div class="header-actions">
            <?php if (!empty($user)): ?>
                <nav class="topnav" aria-label="Main navigation">
                    <a href="/">Projects</a>
                    <span class="account" title="<?= $e($user['email']) ?>"><?= $e($user['email']) ?></span>
                    <form method="post" action="/logout">
                        <input type="hidden" name="_csrf" value="<?= $e(\App\Support\Csrf::token()) ?>">
                        <button class="quiet" type="submit">Sign out</button>
                    </form>
                </nav>
            <?php endif; ?>
            <button class="theme-toggle" type="button" data-theme-toggle hidden>
                <span class="theme-icon" aria-hidden="true"></span>
                <span class="theme-label">Dark mode</span>
            </button>
        </div>
    </div>
</header>
<?php if ($flash): ?>
    <div class="flash flash-<?= $e($flash['type']) ?>" role="status"><?= $e($flash['message']) ?></div>
<?php endif; ?>
<main id="main-content">
