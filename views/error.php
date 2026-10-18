<?php
/** @var callable $e */
$bodyClass = 'page-message';
$pageTitle = $title;
$details = $details ?? [];
?>
<main>
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
    <p class="message-action"><a href="?" class="link-primary">← Back to files</a></p>
<?php endif; ?>
</main>
