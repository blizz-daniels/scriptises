<?php require dirname(__DIR__) . '/partials/header.php'; $e = static fn (mixed $value): string => \App\Support\View::escape((string) $value); ?>
<section class="auth-shell">
    <div class="auth-card">
        <h1>Create the first account</h1>
        <p class="muted">Set up your private writing workspace.</p>
        <form action="/setup" method="post" class="form-stack">
            <input type="hidden" name="_csrf" value="<?= $e(\App\Support\Csrf::token()) ?>">
            <label>Email <input type="email" name="email" autocomplete="email" required></label>
            <label>Password <input type="password" name="password" autocomplete="new-password" minlength="12" required></label>
            <label>Confirm password <input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></label>
            <button class="primary" type="submit">Create private account</button>
        </form>
    </div>
    <div class="auth-art" aria-hidden="true"></div>
</section>
<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
