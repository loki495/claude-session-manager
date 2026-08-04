<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="<?= $this->e($viewportContent) ?>">
<title><?= $this->e($title) ?></title>
<?= $this->section('head-extra', '') ?>
<script src="https://cdn.tailwindcss.com"></script>
<?= $this->section('style', '') ?>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">
<?= $this->section('content') ?>
</body>
</html>
