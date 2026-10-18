<?php
/** @var callable $e */
$bodyClass = 'b2-page-mfa-setup';
$pageTitle = 'MFA setup';
?>
<section class="b2-card b2-mfa-setup">
    <h1 class="b2-page-title">🔐 Two-factor authentication setup</h1>
    <div class="notice">Anyone who sees this page can generate your codes. Close it once you have enrolled, and keep <code>auth.mfa_setup_page</code> off otherwise.</div>
    <ol class="b2-steps">
        <li>Install an authenticator app (Aegis, 2FAS, Google Authenticator, Microsoft Authenticator, etc.).</li>
        <li>Scan this QR code:
            <div id="qrcode-container"><div id="qrcode" data-otpauth="<?= $e($uri) ?>"></div></div>
            <div id="qr-fallback" class="notice" hidden>⚠️ The QR code could not be drawn. Enter the secret below manually.</div>
        </li>
        <li>Or enter this secret manually (time-based, SHA-1, 6 digits, 30 seconds):
            <div class="secret"><?= $e($secret) ?></div>
        </li>
    </ol>
    <p class="message-action"><a href="?">← Back to files</a></p>
    <script src="assets/qrcode-generator.js" defer></script>
</section>
