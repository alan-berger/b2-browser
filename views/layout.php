<?php
/** @var callable $e @var string $content @var string $siteTitle */
$bodyClass = $bodyClass ?? '';
$pageTitle = isset($pageTitle) && $pageTitle !== '' ? $pageTitle . ' — ' . $siteTitle : $siteTitle;
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
<?php if (isset($refreshTo)): ?>
    <meta http-equiv="refresh" content="3;url=<?= $e($refreshTo) ?>">
<?php endif; ?>
    <title><?= $e($pageTitle) ?></title>
    <link rel="stylesheet" href="assets/b2-browse.css">
    <script src="assets/app.js" defer></script>
</head>
<body class="<?= $e($bodyClass) ?>">
<?= $content ?>
</body>
</html>
