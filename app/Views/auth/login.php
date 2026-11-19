<?php require dirname(__DIR__) . '/partials/header.php'; $e = static fn (mixed $value): string => \App\Support\View::escape((string) $value); ?>
<section class="auth-shell">
    <div class="auth-card">
        <h1>Welcome back</h1>
        <p class="muted">Sign in to open your writing desk.</p>
        <form action="/login" method="post" class="form-stack">
            <input type="hidden" name="_csrf" value="<?= $e(\App\Support\Csrf::token()) ?>">
            <label>Email <input type="email" name="email" autocomplete="email" required autofocus></label>
            <label>Password <input type="password" name="password" autocomplete="current-password" required></label>
            <button class="primary" type="submit">Sign in</button>
        </form>
    </div>
    <div class="auth-art" aria-hidden="true"></div>
</section>
<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
