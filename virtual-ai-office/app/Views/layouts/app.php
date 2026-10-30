<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="base-url" content="<?= esc(rtrim(asset_path(), '/')) ?>">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <meta name="csrf-header" content="<?= esc(config('Security')->headerName) ?>">
    <title><?= esc($title ?? 'Virtual AI Office') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset_path('assets/css/app.css') ?>">
    <?= $this->renderSection('head') ?>
</head>
<body class="<?= esc($bodyClass ?? '') ?>">
<?= $this->renderSection('content') ?>
<?= $this->renderSection('scripts') ?>
</body>
</html>
