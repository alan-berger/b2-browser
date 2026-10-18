<?php /** @var callable $e */ ?>
<div class="session-bar">
    <div>
        Signed in as <strong><?= $e($user) ?></strong>
        | Session ends in <strong><?= $e($minutesLeft) ?> min</strong>
<?php if ($mfaSetupLink): ?>
        | <a href="?mfa_setup=1" class="link-mfa">🔐 MFA setup</a>
<?php endif; ?>
    </div>
    <form method="post" action="" class="logout-form" data-confirm="Sign out?">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <button type="submit" class="link-logout">🚪 Sign out</button>
    </form>
</div>
