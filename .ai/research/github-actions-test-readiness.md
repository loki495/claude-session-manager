---
topic: github-actions-test-readiness
covers:
  - path: tests/run.sh
    hash: 046672db30a1398ba11c667f814da76186b1b5d5
  - path: tests/playwright/playwright.config.js
    hash: 7e48fa0b961f13364cbf83598fd5d93c560143fd
updated: 2026-09-02
---

Core CI is straightforward on Ubuntu: install PHP/Composer plus `tmux`, run `composer install`, `composer phpstan`, and `bash tests/run.sh --no-browser`. The suite is fixture-isolated and guards against the real tmux/socket paths.

Browser CI needs a little hardening first. The PHP CDP tests can use installed Chrome/Chromium but currently showed navigation timing flakes during a local headless run. The separate `tests/playwright` config hardcodes `/usr/bin/google-chrome-stable`, so it should either install that exact package or allow Playwright's downloaded Chromium executable. Live Codex E2E intentionally requires a deployed Sessioneer instance and local bridge, so it should remain manual/self-hosted rather than run on ordinary GitHub-hosted runners.
