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
<body class="bg-slate-950 text-slate-100 min-h-screen overscroll-y-none">
<!-- overscroll-y-none (not the both-axes overscroll-none this replaced,
     2026-08-09) - Y-axis only, so vertical rubber-banding/scroll-chaining
     past the top/bottom of the page is blocked without also touching
     X-axis overscroll-behavior, which the iOS edge-swipe-back gesture
     (see navigation-blanket above) rides on. -->
<!-- Rendered before the content section, not after - common.js's
     document.getElementById() lookups for this run as soon as its own
     <script> tag is hit, at the END of the content section below, so
     this has to already exist in the DOM by then or those lookups
     silently come back null and the whole feature no-ops. -->
<!-- Covers any navigation away from this page (the iOS edge-swipe-back
     gesture included - it has no click handler to hook, unlike a normal
     link tap, which is why this can't just be a per-link loading state)
     with the same background the page itself already sits on, rather
     than leaving the old page's content frozen on screen for however
     long the browser takes to actually swap in the next one - see
     common.js's pagehide/pageshow handling. z-[60], one above the
     fullscreen text modal's z-50, so it still wins if a navigation
     happens to fire while that's open too. -->
<div id="navigation-blanket" class="hidden fixed inset-0 z-[60] bg-slate-950 flex items-center justify-center">
  <span class="flex items-center gap-1.5">
    <span class="inline-block w-2 h-2 rounded-full bg-sky-400 animate-bounce" style="animation-delay:0ms"></span>
    <span class="inline-block w-2 h-2 rounded-full bg-sky-400 animate-bounce" style="animation-delay:150ms"></span>
    <span class="inline-block w-2 h-2 rounded-full bg-sky-400 animate-bounce" style="animation-delay:300ms"></span>
  </span>
</div>
<div id="fullscreen-text-modal" class="hidden fixed inset-0 z-50 bg-slate-950 flex flex-col">
  <div class="select-none flex items-center justify-between px-2 py-2 border-b border-slate-800 shrink-0">
    <button type="button" id="fullscreen-text-modal-wrap-toggle" aria-pressed="false" class="text-xs text-slate-400 active:text-slate-200 border border-slate-700 rounded px-2 py-1">Wrap: Off</button>
    <button type="button" id="fullscreen-text-modal-close" aria-label="Close full screen view" class="text-slate-400 active:text-slate-200 text-2xl leading-none px-3 py-1">&times;</button>
  </div>
  <pre id="fullscreen-text-modal-content" class="flex-1 overflow-auto overscroll-contain whitespace-pre px-3 py-2 text-xs text-slate-100"></pre>
</div>
<?= $this->section('content') ?>
</body>
</html>
