<?php
/** @var callable $e */
$bodyClass = 'mfa-setup-body';
$pageTitle = 'MFA setup';
?>
<main>
    <h1>🔐 Two-factor authentication setup</h1>
    <div class="notice">Anyone who sees this page can generate your codes. Close it once you have enrolled, and keep <code>auth.mfa_setup_page</code> off otherwise.</div>
    <div class="info"><strong>Step 1:</strong> Install an authenticator app (Aegis, 2FAS, Google Authenticator, Microsoft Authenticator, etc.).</div>
    <div class="info"><strong>Step 2:</strong> Scan this QR code:</div>
    <div id="qrcode-container"><div id="qrcode" data-otpauth="<?= $e($uri) ?>"></div></div>
    <div id="qr-fallback" class="qr-fallback" hidden>⚠️ The QR code could not be drawn. Enter the secret below manually.</div>
    <div class="info"><strong>Step 3:</strong> Or enter this secret manually (time-based, SHA-1, 6 digits, 30 seconds):</div>
    <div class="secret"><?= $e($secret) ?></div>
    <p><a href="?" class="link-primary">← Back to files</a></p>
    <script src="assets/qrcode-generator.js" defer></script>
</main>
