import { defineConfig } from '@playwright/test';
import path from 'path';
import { fileURLToPath } from 'url';

const frontendDir = path.dirname(fileURLToPath(import.meta.url));
const rootDir = path.resolve(frontendDir, '..');

export default defineConfig({
  testDir: path.join(frontendDir, 'e2e'),
  testMatch: '**/*.spec.ts',
  timeout: 30_000,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: process.env.CI ? [['github'], ['list']] : 'list',
  use: {
    baseURL: 'http://127.0.0.1:8099',
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  webServer: {
    command: 'bash tests/e2e/serve.sh',
    cwd: rootDir,
    url: 'http://127.0.0.1:8099/login',
    timeout: 20_000,
    reuseExistingServer: !process.env.CI,
  },
});
