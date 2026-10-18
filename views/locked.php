<?php
/** @var callable $e */
$bodyClass = 'page-message';
$pageTitle = 'Temporarily locked';
?>
<main>
    <h1 class="danger-heading">🔒 Temporarily locked</h1>
    <p class="message-text-lg">Too many failed sign-in attempts from your network.</p>
    <p class="message-subtext">Please try again in <strong><?= $e($minutes) ?> minute(s)</strong>.</p>
</main>
