import { test, expect } from '@playwright/test';
import { login } from './helpers';

// Regresní test: editace faktury otevřená ze seznamu musí načíst položky
// z detailu (řádek seznamu items neobsahuje) — jinak validace blokuje
// submit a horší: uložení by skutečné položky smazalo.
test.describe('Faktury - položky v editačním dialogu', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('editace faktury ze seznamu předvyplní položky a dovolí změnu statusu', async ({ page }) => {
    const runId = String(Date.now() % 100000);
    const clientName = `E2E Fakturační Klient ${runId}`;
    const itemDesc = 'E2E testovací položka faktury';
    let invoiceNumber = '';

    await test.step('vytvořit klienta', async () => {
      await page.goto('/clients');
      await page.getByRole('button', { name: 'Nový klient' }).click();
      await page.getByLabel(/Jméno/).fill('E2E');
      await page.getByLabel(/Příjmení/).fill(`Fakturační Klient ${runId}`);
      await page.getByRole('button', { name: 'Vytvořit' }).click();
      await expect(page.getByRole('cell', { name: clientName })).toBeVisible();
    });

    await test.step('vystavit fakturu s položkou', async () => {
      await page.goto('/finance');
      await page.getByRole('button', { name: 'Faktury' }).click();
      await page.getByRole('button', { name: 'Vystavit fakturu' }).click();
      await page.getByLabel('Klient').selectOption({ label: clientName });
      await page.getByLabel(/Datum vystavení/).fill('2026-10-13');
      await page.getByLabel(/Datum splatnosti/).fill('2026-11-13');
      await page.getByPlaceholder('např. Tvorba webu').fill(itemDesc);
      await page.locator('input[step="0.01"]').fill('5000');
      const [resp] = await Promise.all([
        page.waitForResponse(
          (r) => r.url().includes('/api/invoices') && r.request().method() === 'POST'
        ),
        page.getByRole('button', { name: 'Vystavit a stáhnout PDF' }).click(),
      ]);
      expect(resp.status()).toBe(201);
      const body = (await resp.json()) as { invoice_number: string };
      invoiceNumber = body.invoice_number;
      // Po vytvoření dialog automaticky stahuje PDF — počkat na něj
      await page.waitForResponse((r) => r.url().includes('/api/invoices/') && r.url().endsWith('/pdf'));
      await expect(page.getByRole('row').filter({ hasText: invoiceNumber })).toBeVisible();
    });

    await test.step('editace ze seznamu načte položky a status projde', async () => {
      const row = page.getByRole('row').filter({ hasText: invoiceNumber });
      await row.getByRole('button', { name: 'Upravit' }).click();
      // REGRESNÍ ASSERT: položka musí být předvyplněná z detailu, ne prázdná
      await expect(page.getByPlaceholder('např. Tvorba webu')).toHaveValue(itemDesc);
      await page.getByLabel('Status').selectOption('paid');
      const [resp] = await Promise.all([
        page.waitForResponse(
          (r) => r.url().includes('/api/invoices/') && r.request().method() === 'PUT'
        ),
        page.getByRole('button', { name: 'Uložit změny' }).click(),
      ]);
      expect(resp.status()).toBe(200);
      await expect(row.getByText('Zaplaceno')).toBeVisible();
    });

    await test.step('úklid testovacích dat', async () => {
      const confirmDelete = async (rowFilter: string) => {
        const row = page.getByRole('row').filter({ hasText: rowFilter });
        await row.getByRole('button', { name: 'Smazat' }).click();
        await page.getByPlaceholder('SMAZAT').fill('SMAZAT');
        await page.getByRole('button', { name: 'Smazat natrvalo' }).click();
        await expect(row).toHaveCount(0);
      };

      await confirmDelete(invoiceNumber);
      await page.goto('/clients');
      await confirmDelete(clientName);
    });
  });
});
