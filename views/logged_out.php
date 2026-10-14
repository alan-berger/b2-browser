<?php
/** @var callable $e */
$bodyClass = 'page-message';
$pageTitle = 'Signed out';
$refreshTo = $homepage;
?>
<main>
    <h1 class="success-heading">✅ Signed out</h1>
    <p class="message-text">You have been signed out.</p>
    <p class="message-subtext">Redirecting to the homepage…</p>
    <p class="message-action"><a href="<?= $e($homepage) ?>" class="link-primary">← Return to homepage now</a></p>
</main>
