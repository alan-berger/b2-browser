<?php
/** @var callable $e */
$bodyClass = 'b2-page-auth';
$pageTitle = 'Two-factor authentication';
$error = $error ?? '';
?>
<section class="b2-card b2-auth">
    <h1 class="b2-page-title">🔐 Two-factor authentication</h1>
    <p class="b2-lead">Enter the 6-digit code from your authenticator app.</p>
<?php if ($error !== ''): ?>
    <div class="error" role="alert"><?= $e($error) ?></div>
<?php endif; ?>
    <form method="post" action="">
        <input type="hidden" name="action" value="mfa">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <div class="form-group">
            <label for="code">Authentication code</label>
            <input type="text" id="code" name="code" class="otp-input" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" required autofocus autocomplete="one-time-code">
            <div class="help">Codes can only be used once.</div>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Verify</button>
    </form>
</section>
