<?php
/** @var callable $e */
$bodyClass = 'mfa-page-body';
$pageTitle = 'Sign in';
?>
<main class="mfa-container">
    <h1>🔒 <?= $e($siteTitle) ?></h1>
<?php if ($notice !== ''): ?>
    <div class="notice"><?= $e($notice) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="error" role="alert"><?= $e($error) ?></div>
<?php endif; ?>
    <form method="post" action="">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <div class="form-group">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false">
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn">Sign in</button>
    </form>
    <p class="message-action"><a href="<?= $e($homepage) ?>" class="link-primary">← Return to homepage</a></p>
</main>
