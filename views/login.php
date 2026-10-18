<?php
/** @var callable $e */
$bodyClass = 'b2-page-auth';
$pageTitle = 'Sign in';
?>
<section class="b2-card b2-auth">
    <h1 class="b2-page-title">🔒 <?= $e($siteTitle) ?></h1>
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
        <button type="submit" class="btn btn-primary btn-block">Sign in</button>
    </form>
</section>
