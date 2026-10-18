<?php
/** @var callable $e */
$bodyClass = 'b2-page-message';
$pageTitle = 'Temporarily locked';
?>
<section class="b2-message">
    <h1 class="danger-heading">🔒 Temporarily locked</h1>
    <p class="message-text">Too many failed sign-in attempts from your network.</p>
    <p class="message-subtext">Please try again in <strong><?= $e($minutes) ?> minute(s)</strong>.</p>
</section>
