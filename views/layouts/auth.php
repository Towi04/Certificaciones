<?php
/** @var string $contentFile */
$title = $title ?? 'Acceso';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <link rel="icon" href="<?= e(asset('/assets/brand/favicon.ico')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/css/app.css')) ?>">
</head>
<body>
<?php require $contentFile; ?>
</body>
</html>
