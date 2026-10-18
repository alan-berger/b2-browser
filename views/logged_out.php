<?php
/** @var callable $e */
$bodyClass = 'b2-page-message';
$pageTitle = 'Signed out';
$refreshTo = $homepage;
?>
<section class="b2-message">
    <h1 class="success-heading">✅ Signed out</h1>
    <p class="message-text">You have been signed out.</p>
    <p class="message-subtext">Redirecting to the homepage…</p>
    <p class="message-action"><a href="<?= $e($homepage) ?>">← Return to homepage now</a></p>
</section>
