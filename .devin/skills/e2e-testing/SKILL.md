---
name: e2e-testing
description: "Psaní a rozšiřování Playwright E2E testů v Dev App Pro — konvence speců, test DB, selektory, mockování API, visual regression. Použij při přidávání/úpravě E2E speců nebo nové stránce."
---

# E2E testy — Dev App Pro

Jak psát nové specy do existující infrastruktury (9 speců, běží v CI).

## Architektura (ne měnit bez důvodu)

```
frontend/e2e/*.spec.ts   — specy + helpers.ts (login)
frontend/playwright.config.ts — testDir, webServer
tests/e2e/serve.sh       — php -S 127.0.0.1:8099 + seed usera
tests/e2e/seed-user.php  — vytvoří devapppro_test schéma + e2e_admin
tests/test-router.php    — router (API + /assets/ + SPA fallback na dist)
```

- **`workers: 1`** — sdílená test DB, paralelismus zakázaný
- `bin/test.sh e2e` = `npm run build` + `npx playwright test` (v `frontend/`)
- CI: E2E běží v `test` jobu (`.github/workflows/tests.yml`)

## Bezpečnostní pravidla

- Server vždy `DB_NAME=devapppro_test` — `test-router.php` a `seed-user.php`
  mají `_test` guard, **při úpravách nikdy neobejít**
- Port 8099 (PHPUnit integration server používá 8080 — nekolidovat)
- Test credentials jen v `serve.sh`/env `E2E_USERNAME`/`E2E_PASSWORD` —
  nikdy reálné heslo

## Konvence psaní speců

- [ ] Nový spec = `frontend/e2e/<téma>.spec.ts`, česky pojmenované testy
- [ ] Auth = `import { login } from './helpers'` + `test.beforeEach(login)`
  (login assertuje Nástěnku — je to i smoke test authu)
- [ ] **Selektory:** `getByRole` / `getByLabel` / `getByText` s českými
  texty; `#id` jen kde role nedává smysl (`#username` login). Pozor:
  `CardTitle` renderuje `<div>`, ne heading — nadpisy stránek jsou `<h1>`
- [ ] **Čekání:** nikdy `waitForTimeout` — `toBeVisible()`, `waitForResponse`,
  `waitForURL`, `networkidle` jen pro ad-hoc QA (ne v CI speccích)
- [ ] Nová chráněná stránka → přidat řádek do pole `pages` v `smoke.spec.ts`
- [ ] Test data: jen read + syntetická data přes UI; destruktivní akce
  přes ConfirmDialog testovat jen na datech vytvořených testem

## Pokročilé techniky (když základ nestačí)

### Page Object Model — až když specy přerostou

Teď nepotřeba (9 speců). Když se login/CRUD začne duplikovat:

```typescript
// e2e/pages/ClientsPage.ts
export class ClientsPage {
  constructor(private page: Page) {}
  async goto() { await this.page.goto('/clients'); }
  async createClient(name: string) { /* fill dialog, submit */ }
}
```

### Mockování API — `page.route`

Pro chybové stavy/edge cases bez úpravy backendu:

```typescript
test('chybová hláška při pádu API', async ({ page }) => {
  await page.route('**/api/clients**', r => r.fulfill({ status: 500 }));
  await login(page);
  await page.goto('/clients');
  await expect(page.getByText(/chyba|nepodařilo/i)).toBeVisible();
});
```

### Čekání na konkrétní API

```typescript
const resp = page.waitForResponse(r => r.url().includes('/api/clients'));
await page.goto('/clients');
expect((await resp).status()).toBe(200);
```

### `test.step` u delších toků

```typescript
await test.step('vytvořit klienta', async () => { /* ... */ });
await test.step('uložit a ověřit v tabulce', async () => { /* ... */ });
```

### Visual regression (opt-in)

`await expect(page).toHaveScreenshot('clients.png', { maxDiffPixels: 100 })`
— zavádět jen pro stabilní UI bez dynamických dat (grafy, data v tabulkách
nejsou screenshot-safe).

### Debugging failujícího specu

```bash
cd frontend && npx playwright test --debug          # inspector
npx playwright test --headed                        # viditelný browser
npx playwright show-trace test-results/.../trace.zip  # trace po failu
# nebo v testu: await page.pause();
```

## Checklist před commitem specu

- [ ] `bin/test.sh e2e` zelený lokálně
- [ ] Spec běží deterministicky (žádné `waitForTimeout`, závislost na pořadí)
- [ ] Selektory přes role/label, ne CSS třídy/nth-child
- [ ] Test data izolovaná na `devapppro_test`
