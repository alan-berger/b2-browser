<?php
/** @var callable $e */
$bodyClass = 'mfa-page-body';
$pageTitle = 'Two-factor authentication';
$error = $error ?? '';
?>
<main class="mfa-container">
    <h1>🔐 Two-factor authentication</h1>
    <p>Enter the 6-digit code from your authenticator app.</p>
<?php if ($error !== ''): ?>
    <div class="error" role="alert"><?= $e($error) ?></div>
<?php endif; ?>
    <form method="post" action="">
        <input type="hidden" name="action" value="mfa">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <div class="form-group">
            <label for="code">Authentication code</label>
            <input type="text" id="code" name="code" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" required autofocus autocomplete="one-time-code">
            <div class="help">Codes can only be used once.</div>
        </div>
        <button type="submit" class="btn">Verify</button>
    </form>
</main>
