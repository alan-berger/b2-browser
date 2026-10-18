<?php
/** @var callable $e */
$bodyClass = 'b2-page-message';
$pageTitle = $title;
$details = $details ?? [];
?>
<section class="b2-message">
    <h1 class="danger-heading"><?= $e($title) ?></h1>
    <p class="message-text"><?= $e($message) ?></p>
<?php if ($details !== []): ?>
    <ul class="error-details">
<?php foreach ($details as $detail): ?>
        <li><?= $e($detail) ?></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php if (!empty($back)): ?>
    <p class="message-action"><a href="?">← Back to files</a></p>
<?php endif; ?>
</section>
