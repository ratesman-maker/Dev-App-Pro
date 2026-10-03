import { Page, expect } from '@playwright/test';

export const E2E_USER = process.env.E2E_USERNAME || 'e2e_admin';
export const E2E_PASS = process.env.E2E_PASSWORD || 'e2e_test_heslo_123';

export async function login(page: Page): Promise<void> {
  await page.goto('/login');
  await page.fill('#username', E2E_USER);
  await page.fill('#password', E2E_PASS);
  await page.click('button[type="submit"]');
  await expect(page.getByRole('heading', { name: 'Nástěnka' })).toBeVisible();
}
