<?php
/**
 * Page shell.
 *
 * Two modes:
 *  - Site markup (theme.classes set): reproduces the host site's own header
 *    and menu — logo + <h1> in the header element, links and theme button in
 *    the nav element, all inside the site's wrapper — so the site's own
 *    stylesheet lays them out pixel-for-pixel as on its other pages.
 *  - Built-in: b2-browser's own branding block (logo, heading, nav) styled by
 *    b2-browse.css; with no theme at all only the light/dark toggle shows.
 *
 * @var callable $e @var string $content @var string $siteTitle
 * @var array{stylesheets: list<string>, logo: string, logoAlt: string, heading: string, headingLink: string,
 *            nav: list<array{0: string, 1: string}>, favicon: string, toggle: bool, storageKey: string,
 *            classes: array{wrapper: string, header: string, nav: string, toggle: string}|null} $theme
 */
$bodyClass = trim('b2 ' . ($bodyClass ?? '') . ($theme['classes'] !== null ? ' b2-site-markup' : ''));
$pageTitle = isset($pageTitle) && $pageTitle !== '' ? $pageTitle . ' — ' . $siteTitle : $siteTitle;
$hasBrand = $theme['logo'] !== '' || $theme['heading'] !== '';
$classes = $theme['classes'];
// The label is chosen by CSS from <html data-theme>, which theme-init.js sets
// before first paint, so the button never changes width after load.
$toggleLabel = '<span class="b2-when-light">DARK</span><span class="b2-when-dark">LIGHT</span>';
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
<?php if ($classes !== null): ?>
<div class="<?= $e($classes['wrapper']) ?>">
	  <div class="<?= $e($classes['header']) ?>">
	  <?php if ($theme['logo'] !== ''): ?><img src="<?= $e($theme['logo']) ?>" alt="<?= $e($theme['logoAlt']) ?>" width="64" height="64"><?php endif; ?><h1><?= $e($theme['heading'] !== '' ? $theme['heading'] : $siteTitle) ?></h1>
	  </div>
	  <div class="<?= $e($classes['nav']) ?>">
<?php foreach ($theme['nav'] as [$label, $href]): ?>
<a href="<?= $e($href) ?>"><?= $e($label) ?></a>
<?php endforeach; ?>
<?php if ($theme['toggle']): ?>
<button type="button" class="<?= $e($classes['toggle']) ?>" id="themeToggle" aria-label="Toggle dark mode"><?= $toggleLabel ?></button>
<?php endif; ?>
	  </div>
    <main class="b2-main">
<?= $content ?>
    </main>
</div>
<?php else: ?>
<div class="b2-shell">
<?php if ($hasBrand): ?>
    <header class="b2-brand">
        <a href="<?= $e($theme['headingLink']) ?>" class="b2-brand-link">
<?php if ($theme['logo'] !== ''): ?>
            <img src="<?= $e($theme['logo']) ?>" alt="<?= $e($theme['logoAlt']) ?>" class="b2-brand-logo" width="64" height="64">
<?php endif; ?>
<?php if ($theme['heading'] !== ''): ?>
            <span class="b2-brand-heading"><?= $e($theme['heading']) ?></span>
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
        <button type="button" class="b2-theme-toggle" id="themeToggle" aria-label="Toggle dark mode"><?= $toggleLabel ?></button>
<?php endif; ?>
    </nav>
<?php endif; ?>
    <main class="b2-main">
<?= $content ?>
    </main>
</div>
<?php endif; ?>
</body>
</html>
