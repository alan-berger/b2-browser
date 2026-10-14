<?php
/** @var callable $e @var callable $url */
$bodyClass = 'player-page';
$pageTitle = $label;
?>
<div class="header">
    <h1>🎥 <?= $e($label) ?></h1>
</div>
<div class="video-container">
    <video controls autoplay muted playsinline preload="metadata" id="videoPlayer">
        <source src="<?= $e($src) ?>" type="<?= $e($mime) ?>">
        Your browser does not support the video tag.
    </video>
</div>
<div id="videoError" class="notice" hidden>This video could not be played in the browser. Try downloading it instead.</div>
<div class="info">
<?php if ($date !== null): ?>
    <span class="info-item"><span class="info-label">Date:</span><?= $e($date) ?></span>
<?php endif; ?>
<?php if ($camera !== null): ?>
    <span class="info-item"><span class="info-label">Camera:</span><?= $e($camera) ?></span>
<?php endif; ?>
    <span class="info-item"><span class="info-label">File:</span><?= $e($label) ?></span>
    <span class="info-item"><span class="info-label">Link valid:</span><?= $e($ttlMinutes) ?> min</span>
</div>
<div class="controls">
<?php if ($ios): ?>
    <a href="<?= $e('vlc://' . $src) ?>" class="btn btn-vlc">▶️ Open in VLC</a>
<?php endif; ?>
    <a href="<?= $e($url(['download' => $file])) ?>" class="btn">⬇️ Download</a>
    <a href="<?= $e($url(['path' => $parent])) ?>" class="btn btn-secondary">← Back to folder</a>
</div>
<p class="keyboard-help">Keys: space/k play–pause · f fullscreen · m mute · ←/→ seek 5 s</p>
