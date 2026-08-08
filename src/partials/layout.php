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
<body class="bg-slate-950 text-slate-100 min-h-screen overscroll-none">
<!-- Rendered before the content section, not after - common.js's
     document.getElementById() lookups for this run as soon as its own
     <script> tag is hit, at the END of the content section below, so
     this has to already exist in the DOM by then or those lookups
     silently come back null and the whole feature no-ops. -->
<div id="fullscreen-text-modal" class="hidden fixed inset-0 z-50 bg-slate-950 flex flex-col">
  <div class="select-none flex items-center justify-end px-2 py-2 border-b border-slate-800 shrink-0">
    <button type="button" id="fullscreen-text-modal-close" aria-label="Close full screen view" class="text-slate-400 active:text-slate-200 text-2xl leading-none px-3 py-1">&times;</button>
  </div>
  <pre id="fullscreen-text-modal-content" class="flex-1 overflow-auto overscroll-contain whitespace-pre px-3 py-2 text-xs text-slate-100"></pre>
</div>
<?= $this->section('content') ?>
</body>
</html>
