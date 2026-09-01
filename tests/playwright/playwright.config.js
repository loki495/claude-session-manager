const { defineConfig } = require('@playwright/test');

const live = process.env.SESSIONEER_PLAYWRIGHT_LIVE === '1';

module.exports = defineConfig({
  testDir: '.',
  testMatch: '**/*.spec.js',
  fullyParallel: false,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL: process.env.SESSIONEER_PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:18100',
    browserName: 'chromium',
    launchOptions: {
      executablePath: '/usr/bin/google-chrome-stable',
      args: ['--no-sandbox'],
    },
    trace: 'retain-on-failure',
  },
  webServer: live ? undefined : {
    command: 'node server.js',
    url: 'http://127.0.0.1:18100/',
    reuseExistingServer: false,
    timeout: 10000,
  },
});
