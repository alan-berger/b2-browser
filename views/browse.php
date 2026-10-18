<?php
/**
 * @var callable $e @var callable $url @var \B2Browser\View $view
 * @var list<array<string, mixed>> $items @var list<array{label: string, path: string}> $crumbs
 */
use B2Browser\View;

$pageTitle = '';
?>
<div class="container">
    <header>
        <h1>📹 <?= $e($siteTitle) ?></h1>
<?php if ($authenticated): ?>
        <?= $view->partial('session_bar', compact('user', 'minutesLeft', 'mfaSetupLink', 'csrf')) ?>
<?php endif; ?>
        <nav class="breadcrumb" aria-label="Folder">
            <a href="?">🏠 Home</a>
<?php foreach ($crumbs as $crumb): ?>
            <span aria-hidden="true">/</span> <a href="<?= $e($url(['path' => $crumb['path']])) ?>"><?= $e($crumb['label']) ?></a>
<?php endforeach; ?>
        </nav>
<?php if ($dateJump): ?>
        <form method="get" action="" class="date-jump">
            <label for="date">Jump to date</label>
            <input type="date" id="date" name="date" value="<?= $e($today) ?>" max="<?= $e($today) ?>" required>
            <button type="submit" class="btn btn-download">Go</button>
        </form>
<?php endif; ?>
<?php if ($debug): ?>
        <div class="debug-info"><strong>Debug:</strong> folder "<?= $e($folder) ?>", page <?= $e($page) ?>, <?= $e(count($items)) ?> items</div>
<?php endif; ?>
    </header>

<?php if ($items !== []): ?>
    <div class="filter-bar">
        <label for="fileTypeFilter">Filter:</label>
        <select id="fileTypeFilter">
            <option value="all">All files</option>
            <option value="video">Videos only</option>
            <option value="image">Images only</option>
            <option value="folder">Folders only</option>
        </select>
        <input type="search" id="fileNameSearch" placeholder="Search this page by name…" class="search-input" aria-label="Search by filename">
    </div>
<?php endif; ?>

    <div class="file-list">
<?php if ($items === []): ?>
        <div class="empty-state">
            <div class="empty-state-icon">📁</div>
            <p>No files or folders here.</p>
        </div>
<?php endif; ?>
<?php foreach ($items as $item): ?>
        <div class="file-item" data-type="<?= $e($item['type']) ?>" data-name="<?= $e(mb_strtolower($item['label'])) ?>">
            <div class="file-icon">
<?php if ($item['thumb'] !== null): ?>
                <a href="<?= $e($item['thumb']) ?>" target="_blank" rel="noopener noreferrer"><img src="<?= $e($item['thumb']) ?>" alt="" class="thumb" loading="lazy" decoding="async"></a>
<?php else: ?>
                <?= ['folder' => '📁', 'video' => '🎥', 'image' => '🖼️'][$item['type']] ?? '📄' ?>
<?php endif; ?>
            </div>
            <div class="file-info">
                <div class="file-name">
<?php if ($item['type'] === 'folder'): ?>
                    <a href="<?= $e($url(['path' => $item['name']])) ?>"><?= $e($item['label']) ?></a>
<?php else: ?>
                    <?= $e($item['label']) ?>
<?php if ($item['type'] === 'video'): ?>
                    <span class="camera-badge video-badge">VIDEO</span>
<?php elseif ($item['type'] === 'image'): ?>
                    <span class="camera-badge image-badge">IMAGE</span>
<?php endif; ?>
<?php endif; ?>
                </div>
                <div class="file-meta">
<?php if ($item['type'] === 'folder'): ?>
                    Folder
<?php else: ?>
                    <?= $e(View::bytes($item['size'])) ?>
<?php if ($item['date'] !== null): ?> • <?= $e($item['date']) ?><?php endif; ?>
<?php if ($item['camera'] !== null): ?> • Camera <?= $e($item['camera']) ?><?php endif; ?>
<?php if ($item['uploaded'] !== null): ?> • uploaded <?= $e(date('Y-m-d H:i', $item['uploaded'])) ?><?php endif; ?>
<?php endif; ?>
                </div>
            </div>
<?php if ($item['type'] !== 'folder'): ?>
            <div class="file-actions">
<?php if ($item['type'] === 'video'): ?>
                <a href="<?= $e($url(['play' => $item['name']])) ?>" class="btn btn-view">▶️ Play</a>
<?php endif; ?>
                <a href="<?= $e($url(['download' => $item['name']])) ?>" class="btn btn-download">⬇️ Download</a>
            </div>
<?php endif; ?>
        </div>
<?php endforeach; ?>
    </div>

<?php if ($page > 1 || $hasNext): ?>
    <nav class="pagination" aria-label="Pages">
<?php if ($page > 1): ?>
        <a href="<?= $e($url(['path' => $folder, 'page' => $page - 1])) ?>">← Previous</a>
<?php else: ?>
        <span class="spacer"></span>
<?php endif; ?>
        <span class="page-info">Page <?= $e($page) ?> · <?= $e(count($items)) ?> items<?= $hasNext ? ' · more available' : '' ?></span>
<?php if ($hasNext): ?>
        <a href="<?= $e($url(['path' => $folder, 'page' => $page + 1])) ?>">Next →</a>
<?php else: ?>
        <span class="spacer"></span>
<?php endif; ?>
    </nav>
<?php endif; ?>
</div>
