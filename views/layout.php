<?php
/**
 * Page shell: optional site branding (logo, heading, navigation, theme
 * toggle) around every page. With no theme configured only the light/dark
 * toggle is shown, top right.
 *
 * @var callable $e @var string $content @var string $siteTitle
 * @var array{stylesheets: list<string>, logo: string, logoAlt: string, heading: string, headingLink: string,
 *            nav: list<array{0: string, 1: string}>, favicon: string, toggle: bool, storageKey: string} $theme
 */
$bodyClass = trim('b2 ' . ($bodyClass ?? ''));
$pageTitle = isset($pageTitle) && $pageTitle !== '' ? $pageTitle . ' — ' . $siteTitle : $siteTitle;
$brandHeading = $theme['heading'];
$hasBrand = $theme['logo'] !== '' || $theme['heading'] !== '';
?><!DOCTYPE html>
<html lang="en" data-theme="light" data-theme-key="<?= $e($theme['storageKey']) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
<?php if (isset($refreshTo)): ?>
    <meta http-equiv="refresh" content="3;url=<?= $e($refreshTo) ?>">
<?php endif; ?>
    <title><?= $e($pageTitle) ?></title>
    <script src="assets/theme-init.js"></script>
<?php if ($theme['favicon'] !== ''): ?>
    <link rel="icon" href="<?= $e($theme['favicon']) ?>">
<?php endif; ?>
<?php foreach ($theme['stylesheets'] as $sheet): ?>
    <link rel="stylesheet" href="<?= $e($sheet) ?>">
<?php endforeach; ?>
    <link rel="stylesheet" href="assets/b2-browse.css">
    <script src="assets/app.js" defer></script>
</head>
<body class="<?= $e($bodyClass) ?>">
<div class="b2-shell">
<?php if ($hasBrand): ?>
    <header class="b2-brand">
        <a href="<?= $e($theme['headingLink']) ?>" class="b2-brand-link">
<?php if ($theme['logo'] !== ''): ?>
            <img src="<?= $e($theme['logo']) ?>" alt="<?= $e($theme['logoAlt']) ?>" class="b2-brand-logo" width="64" height="64">
<?php endif; ?>
<?php if ($brandHeading !== ''): ?>
            <span class="b2-brand-heading"><?= $e($brandHeading) ?></span>
<?php endif; ?>
        </a>
    </header>
<?php endif; ?>
<?php if ($theme['nav'] !== [] || $theme['toggle']): ?>
    <nav class="b2-nav<?= $theme['nav'] === [] ? ' b2-nav-plain' : '' ?>" aria-label="Site">
<?php foreach ($theme['nav'] as [$label, $href]): ?>
        <a href="<?= $e($href) ?>"><?= $e($label) ?></a>
<?php endforeach; ?>
<?php if ($theme['toggle']): ?>
        <button type="button" class="b2-theme-toggle" id="themeToggle" aria-label="Toggle dark mode" hidden>DARK</button>
<?php endif; ?>
    </nav>
<?php endif; ?>
    <main class="b2-main">
<?= $content ?>
    </main>
</div>
</body>
</html>
