import { test, expect } from '@playwright/test';
import { E2E_USER, login } from './helpers';

test.describe('Přihlášení', () => {
  test('login stránka se zobrazí', async ({ page }) => {
    await page.goto('/login');
    await expect(page.getByText('Přihlášení', { exact: true })).toBeVisible();
    await expect(page.locator('#username')).toBeVisible();
    await expect(page.locator('#password')).toBeVisible();
  });

  test('neplatné heslo zobrazí chybu a zůstane na loginu', async ({ page }) => {
    await page.goto('/login');
    await page.fill('#username', E2E_USER);
    await page.fill('#password', 'spatne_heslo_xyz');
    await page.click('button[type="submit"]');
    await expect(page.locator('div.text-destructive')).toBeVisible();
    await expect(page).toHaveURL(/\/login/);
  });

  test('platné přihlášení přejde na nástěnku', async ({ page }) => {
    await login(page);
    await expect(page).toHaveURL(/\/$/);
  });

  test('nepřihlášeného uživatele přesměruje na login', async ({ page }) => {
    await page.goto('/projects');
    await expect(page).toHaveURL(/\/login/);
  });
});
