import { test, expect } from '@playwright/test';
import { login } from './helpers';

test.describe('Smoke - hlavní stránky', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('nástěnka načte data z API', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'Nástěnka' })).toBeVisible();
  });

  const pages: Array<[string, string]> = [
    ['/projects', 'Projekty'],
    ['/tasks', 'Úkoly'],
    ['/clients', 'Klienti'],
  ];

  for (const [path, heading] of pages) {
    test(`stránka ${heading} se vykreslí`, async ({ page }) => {
      await page.goto(path);
      await expect(page.getByRole('heading', { name: heading, exact: true })).toBeVisible();
    });
  }

  test('neexistující stránka zobrazí 404 view', async ({ page }) => {
    await page.goto('/neexistujici-stranka');
    await expect(page.getByText(/404|nenalezena|nenalezen/i).first()).toBeVisible();
  });
});
