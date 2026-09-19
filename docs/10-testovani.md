# Testování

Strategie: **TDD** (testy před implementací), **automatizované**, **kritické cesty + bezpečnost**.

---

## 1. Stack

### 1.1 Backend (PHPUnit)

```bash
composer require --dev phpunit/phpunit
```

- **PHPUnit 11** (PHP 8.3)
- **Mockery** pro mockování DB (přes Composer)
- **MariaDB test DB** - reálná DB, ne mock (pro integritu FK, constraints)
- Test DB: `devapppro_test` (oddělená od produkční)

### 1.2 Frontend (Vitest)

```bash
npm install --save-dev vitest @testing-library/react @testing-library/jest-dom jsdom
```

- **Vitest** (Vite-native, rychlý)
- **@testing-library/react** pro komponenty
- **jsdom** pro DOM simulaci
- **msw** (Mock Service Worker) pro API mock

### 1.3 E2E (volitelně, později)

- **Playwright** - pokud bude potřeba, zatím jen unit + integrační

---

## 2. Struktura

### 2.1 Backend

```
src/
├── Controllers/
├── Repositories/
├── Services/
tests/
├── Unit/
│   ├── AuthTest.php
│   ├── ClientRepositoryTest.php
│   ├── ProjectRepositoryTest.php
│   ├── InvoiceRepositoryTest.php
│   ├── InvoicePdfServiceTest.php
│   └── SettingsRepositoryTest.php
├── Integration/
│   ├── LoginApiTest.php
│   ├── ResetPasswordApiTest.php
│   ├── ClientApiTest.php
│   ├── ProjectApiTest.php
│   ├── ProjectArchiveApiTest.php
│   ├── TaskApiTest.php
│   ├── InvoiceApiTest.php
│   ├── TransactionApiTest.php
│   ├── NoteApiTest.php
│   ├── FileApiTest.php
│   ├── SettingsApiTest.php
│   ├── CompanyProfileApiTest.php
│   └── SyncProjectsTest.php
├── Security/
│   ├── CsrfTest.php
│   ├── RateLimitTest.php
│   ├── SessionHardeningTest.php
│   ├── SqlInjectionTest.php
│   ├── XssTest.php
│   ├── FileUploadTest.php
│   ├── DirectoryTraversalTest.php
│   ├── InputValidationTest.php
│   └── AuthorizationTest.php
├── TestCase.php              - základní třída (setUp DB, klient)
└── phpunit.xml
```

### 2.2 Frontend

```
frontend/src/
├── components/
├── hooks/
├── pages/
frontend/tests/
├── components/
│   ├── Sidebar.test.tsx
│   ├── Topbar.test.tsx
│   ├── ThemeToggle.test.tsx
│   ├── DataTable.test.tsx
│   └── ConfirmDialog.test.tsx
├── hooks/
│   ├── useAuth.test.tsx
│   ├── useTheme.test.tsx
│   ├── useClients.test.tsx
│   └── useProjects.test.tsx
├── pages/
│   ├── LoginPage.test.tsx
│   ├── ResetPasswordPage.test.tsx
│   ├── ClientsPage.test.tsx
│   ├── ProjectsPage.test.tsx
│   └── InvoicesPage.test.tsx
├── lib/
│   ├── utils.test.ts          - fmtDate, fmtMoney, fmtMinutes
│   └── api.test.ts
└── setup.ts                   - Vitest setup (jsdom, msw)
```

---

## 3. TDD pořadí

Testy se píšou **před** implementací. Pořadí podle závislostí (od základů nahoru):

### 3.1 Fáze 1 - Základy (backend)

1. **`TestCase.php`** - základní třída, DB setup/teardown
2. **`SettingsRepositoryTest`** - čtení/zápis settings (používá se všude)
3. **`AuthTest`** - login, logout, session, password_verify
4. **`LoginApiTest`** - HTTP login endpoint, rate limiting
5. **`ResetPasswordApiTest`** - hint, reset, validace
6. **`ClientRepositoryTest`** - CRUD klienti, osoba/firma
7. **`ClientApiTest`** - HTTP endpointy, validace IČO/DIČ

### 3.2 Fáze 2 - Jádro (backend)

8. **`ProjectRepositoryTest`** - CRUD, folder_path
9. **`ProjectApiTest`** - HTTP endpointy, archive/restore
10. **`ProjectArchiveApiTest`** - archivace, obnova, kaskády
11. **`SyncProjectsTest`** - auto-sync ze složek (filesystem mock)
12. **`InvoiceRepositoryTest`** - CRUD, DPH výpočet, číslování
13. **`InvoiceApiTest`** - HTTP, DPH, VS/KS, PDF endpoint
14. **`TaskApiTest`** - CRUD, estimated/spent minutes
15. **`TransactionApiTest`** - CRUD, kategorie, filtry

### 3.3 Fáze 3 - Polymorfní (backend)

16. **`NoteApiTest`** - CRUD, polymorfní vazby
17. **`FileApiTest`** - upload, download, thumbnails, is_image
18. **`SettingsApiTest`** - GET/PUT settings
19. **`CompanyProfileApiTest`** - GET/PUT company_profile
20. **`InvoicePdfServiceTest`** - PDF generování (mPDF)

### 3.4 Fáze 4 - Bezpečnost (backend)

21. **`CsrfTest`** - token generování, validace, rotace
22. **`RateLimitTest`** - 5 pokusů/hodinu, blokace
23. **`SessionHardeningTest`** - cookie flags, strict mode, gc
24. **`SqlInjectionTest`** - prepared statements, bind params
25. **`XssTest`** - escapování výstupu, CSP
26. **`FileUploadTest`** - MIME, velikost, extension, directory traversal
27. **`DirectoryTraversalTest`** - realpath, symlinky, `..`
28. **`InputValidationTest`** - IČO, DIČ, email, IBAN, velikosti
29. **`AuthorizationTest`** - nepřihlášený přístup, admin-only endpoints

### 3.5 Fáze 5 - Frontend

30. **`utils.test.ts`** - fmtDate, fmtMoney, fmtMinutes, fmtClientName
31. **`api.test.ts`** - fetch wrapper, CSRF, error handling
32. **`useAuth.test.tsx`** - login, logout, session
33. **`useTheme.test.tsx`** - localStorage, DB sync, toggle
34. **`LoginPage.test.tsx`** - formulář, reset, theme toggle
35. **`Sidebar.test.tsx`** - 8 položek, collapse, prefetch
36. **`ClientsPage.test.tsx`** - DataTable, dialog, akce
37. **`ProjectsPage.test.tsx`** - DataTable, archive, restore
38. **`InvoicesPage.test.tsx`** - DPH, VS/KS, PDF download

---

## 4. Backend testy - specifikace

### 4.1 TestCase.php (základ)

```php
class TestCase extends \PHPUnit\Framework\TestCase
{
    protected PDO $pdo;
    protected Client $http;  // Guzzle nebo Symfony HttpClient

    protected function setUp(): void
    {
        // 1. Připojit test DB (devapppro_test)
        $this->pdo = new PDO('mysql:host=127.0.0.1;dbname=devapppro_test', ...);
        // 2. Načíst schema.sql
        $this->pdo->exec(file_get_contents('database/schema.sql'));
        // 3. Načíst seed (settings, company_profile, admin user)
        $this->pdo->exec(file_get_contents('database/seed_test.sql'));
        // 4. HTTP klient na 127.0.0.1:80
        $this->http = new Client(['base_uri' => 'http://127.0.0.1']);
    }

    protected function tearDown(): void
    {
        // Drop all tables (čistý stav pro další test)
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($this->pdo->query('SHOW TABLES')->fetchAll() as $row) {
            $this->pdo->exec('DROP TABLE ' . array_values($row)[0]);
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    protected function login(string $username = 'admin', string $password = 'test123'): void
    {
        $this->http->post('/api/auth/login', [
            'json' => ['username' => $username, 'password' => $password]
        ]);
    }
}
```

### 4.2 LoginApiTest

```php
class LoginApiTest extends TestCase
{
    public function test_uspesne_prihlaseni(): void
    {
        $response = $this->http->post('/api/auth/login', [
            'json' => ['username' => 'admin', 'password' => 'test123']
        ]);
        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertEquals('admin', $body['user']['username']);
        $this->assertEquals('dark', $body['user']['theme']);
    }

    public function test_spatne_heslo_vrati_401(): void
    {
        $response = $this->http->post('/api/auth/login', [
            'json' => ['username' => 'admin', 'password' => 'spatne']
        ]);
        $this->assertEquals(401, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertStringContainsString('Neplatné', $body['error']);
        // Generická zpráva - ne prozradit, co je špatně
        $this->assertStringNotContainsString('heslo', $body['error']);
    }

    public function test_neexistujici_uzivatel_vrati_401(): void
    {
        $response = $this->http->post('/api/auth/login', [
            'json' => ['username' => 'neexistuje', 'password' => 'cokoliv']
        ]);
        $this->assertEquals(401, $response->getStatusCode());
        // Stejná zpráva jako špatné heslo (ne prozradit)
    }

    public function test_prihlaseni_regeneruje_session_id(): void
    {
        $response1 = $this->http->get('/api/auth/me');
        $sessionBefore = $response1->getHeader('Set-Cookie');

        $this->http->post('/api/auth/login', [
            'json' => ['username' => 'admin', 'password' => 'test123']
        ]);

        $response2 = $this->http->get('/api/auth/me');
        $sessionAfter = $response2->getHeader('Set-Cookie');

        $this->assertNotEquals($sessionBefore, $sessionAfter);
    }

    public function test_rate_limit_blokuje_po_5_pokusech(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->http->post('/api/auth/login', [
                'json' => ['username' => 'admin', 'password' => 'spatne']
            ]);
        }
        // 6. pokus by měl být blokován
        $response = $this->http->post('/api/auth/login', [
            'json' => ['username' => 'admin', 'password' => 'test123']
        ]);
        $this->assertEquals(429, $response->getStatusCode());
    }
}
```

### 4.3 ResetPasswordApiTest

```php
class ResetPasswordApiTest extends TestCase
{
    public function test_hint_vrati_napovedu(): void
    {
        $this->pdo->exec("UPDATE users SET password_hint = 'Muj pes' WHERE username = 'admin'");
        $response = $this->http->post('/api/auth/password-hint', [
            'json' => ['username' => 'admin']
        ]);
        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertEquals('Muj pes', $body['hint']);
    }

    public function test_hint_pro_neexistujiciho_vrati_404(): void
    {
        $response = $this->http->post('/api/auth/password-hint', [
            'json' => ['username' => 'neexistuje']
        ]);
        $this->assertEquals(404, $response->getStatusCode());
    }

    public function test_reset_zmeni_heslo(): void
    {
        $response = $this->http->post('/api/auth/reset-password', [
            'json' => [
                'username' => 'admin',
                'new_password' => 'NoveHeslo123',
                'new_password_confirm' => 'NoveHeslo123'
            ]
        ]);
        $this->assertEquals(200, $response->getStatusCode());

        // Přihlášení se starým heslem selže
        $oldLogin = $this->http->post('/api/auth/login', [
            'json' => ['username' => 'admin', 'password' => 'test123']
        ]);
        $this->assertEquals(401, $oldLogin->getStatusCode());

        // Přihlášení s novým heslem projde
        $newLogin = $this->http->post('/api/auth/login', [
            'json' => ['username' => 'admin', 'password' => 'NoveHeslo123']
        ]);
        $this->assertEquals(200, $newLogin->getStatusCode());
    }

    public function test_reset_neshoda_hesel_vrati_422(): void
    {
        $response = $this->http->post('/api/auth/reset-password', [
            'json' => [
                'username' => 'admin',
                'new_password' => 'Heslo1',
                'new_password_confirm' => 'Heslo2'
            ]
        ]);
        $this->assertEquals(422, $response->getStatusCode());
    }

    public function test_reset_kratke_heslo_vrati_422(): void
    {
        $response = $this->http->post('/api/auth/reset-password', [
            'json' => [
                'username' => 'admin',
                'new_password' => '123',
                'new_password_confirm' => '123'
            ]
        ]);
        $this->assertEquals(422, $response->getStatusCode());
    }
}
```

### 4.4 ClientApiTest

```php
class ClientApiTest extends TestCase
{
    public function test_vytvoreni_osoby(): void
    {
        $this->login();
        $response = $this->http->post('/api/clients', [
            'json' => [
                'type' => 'individual',
                'first_name' => 'Jan',
                'last_name' => 'Novák',
                'email' => 'jan@example.cz',
                'phone' => '+420 123 456 789'
            ]
        ]);
        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertEquals('Jan Novák', $body['full_name']);
        $this->assertNull($body['company_name']);
    }

    public function test_vytvoreni_firmy(): void
    {
        $this->login();
        $response = $this->http->post('/api/clients', [
            'json' => [
                'type' => 'company',
                'company_name' => 'Firma s.r.o.',
                'ico' => '12345678',
                'dic' => 'CZ12345678',
                'bank_account' => '123456789/0100'
            ]
        ]);
        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertEquals('Firma s.r.o.', $body['full_name']);
        $this->assertNull($body['first_name']);
    }

    public function test_osoba_bez_prijmeni_vrati_422(): void
    {
        $this->login();
        $response = $this->http->post('/api/clients', [
            'json' => [
                'type' => 'individual',
                'first_name' => 'Jan'
                // last_name chybí
            ]
        ]);
        $this->assertEquals(422, $response->getStatusCode());
    }

    public function test_firma_bez_nazvu_vrati_422(): void
    {
        $this->login();
        $response = $this->http->post('/api/clients', [
            'json' => [
                'type' => 'company',
                'ico' => '12345678'
                // company_name chybí
            ]
        ]);
        $this->assertEquals(422, $response->getStatusCode());
    }

    public function test_neplatne_ico_vrati_422(): void
    {
        $this->login();
        $response = $this->http->post('/api/clients', [
            'json' => [
                'type' => 'company',
                'company_name' => 'Firma',
                'ico' => 'abc'  // neplatné
            ]
        ]);
        $this->assertEquals(422, $response->getStatusCode());
    }

    public function test_neplatny_email_vrati_422(): void
    {
        $this->login();
        $response = $this->http->post('/api/clients', [
            'json' => [
                'type' => 'individual',
                'first_name' => 'Jan',
                'last_name' => 'Novák',
                'email' => 'neplatny'
            ]
        ]);
        $this->assertEquals(422, $response->getStatusCode());
    }

    public function test_seznam_bez_prihlaseni_vrati_401(): void
    {
        $response = $this->http->get('/api/clients');
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function test_smazani_klienta_nastavi_project_client_id_null(): void
    {
        $this->login();
        // Vytvořit klienta
        $client = $this->http->post('/api/clients', ['json' => [
            'type' => 'individual', 'first_name' => 'Jan', 'last_name' => 'Novák'
        ]]);
        $clientId = json_decode($client->getBody(), true)['id'];
        // Vytvořit projekt s klientem
        $project = $this->http->post('/api/projects', ['json' => [
            'name' => 'Test', 'client_id' => $clientId
        ]]);
        $projectId = json_decode($project->getBody(), true)['id'];
        // Smazat klienta
        $this->http->delete("/api/clients/$clientId");
        // Projekt by měl mít client_id = NULL
        $stmt = $this->pdo->prepare('SELECT client_id FROM projects WHERE id = ?');
        $stmt->execute([$projectId]);
        $this->assertNull($stmt->fetchColumn());
    }
}
```

### 4.5 ProjectApiTest

```php
class ProjectApiTest extends TestCase
{
    public function test_archivace_zmeni_status(): void
    {
        $this->login();
        $project = $this->http->post('/api/projects', ['json' => ['name' => 'Test']]);
        $projectId = json_decode($project->getBody(), true)['id'];

        $response = $this->http->post("/api/projects/$projectId/archive");
        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertEquals('archived', $body['status']);
    }

    public function test_obnova_zmeni_status_na_active(): void
    {
        $this->login();
        $project = $this->http->post('/api/projects', ['json' => ['name' => 'Test']]);
        $projectId = json_decode($project->getBody(), true)['id'];
        $this->http->post("/api/projects/$projectId/archive");

        $response = $this->http->post("/api/projects/$projectId/restore");
        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertEquals('active', $body['status']);
    }

    public function test_archivace_uchova_data(): void
    {
        $this->login();
        $project = $this->http->post('/api/projects', ['json' => [
            'name' => 'Test', 'budget_cents' => 500000
        ]]);
        $projectId = json_decode($project->getBody(), true)['id'];
        $this->http->post("/api/projects/$projectId/archive");

        $response = $this->http->get("/api/projects/$projectId");
        $body = json_decode($response->getBody(), true);
        $this->assertEquals('archived', $body['status']);
        $this->assertEquals(500000, $body['budget_cents']);
    }

    public function test_smazani_nevratne(): void
    {
        $this->login();
        $project = $this->http->post('/api/projects', ['json' => ['name' => 'Test']]);
        $projectId = json_decode($project->getBody(), true)['id'];
        $this->http->delete("/api/projects/$projectId");

        $response = $this->http->get("/api/projects/$projectId");
        $this->assertEquals(404, $response->getStatusCode());
    }
}
```

### 4.6 SyncProjectsTest

```php
class SyncProjectsTest extends TestCase
{
    private string $watchDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->watchDir = sys_get_temp_dir() . '/devapppro_test_' . uniqid();
        mkdir($this->watchDir);
        // Nastavit PROJECTS_WATCH_DIR v config na $this->watchDir
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->watchDir);
        parent::tearDown();
    }

    public function test_nova_slozka_vytvori_projekt(): void
    {
        mkdir($this->watchDir . '/Web redesign');
        $this->runSync();
        $stmt = $this->pdo->query("SELECT * FROM projects WHERE folder_path = 'Web redesign'");
        $this->assertNotFalse($stmt->fetch());
    }

    public function test_smazana_slozka_archivuje_projekt(): void
    {
        mkdir($this->watchDir . '/Web redesign');
        $this->runSync();
        rmdir($this->watchDir . '/Web redesign');
        $this->runSync();
        $stmt = $this->pdo->query("SELECT status FROM projects WHERE folder_path = 'Web redesign'");
        $this->assertEquals('archived', $stmt->fetchColumn());
    }

    public function test_skryta_slozka_se_ignoruje(): void
    {
        mkdir($this->watchDir . '/.skryta');
        $this->runSync();
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM projects WHERE folder_path = '.skryta'");
        $this->assertEquals(0, $stmt->fetchColumn());
    }

    public function test_symlink_se_ignoruje(): void
    {
        mkdir($this->watchDir . '/real');
        symlink($this->watchDir . '/real', $this->watchDir . '/link');
        $this->runSync();
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM projects WHERE folder_path = 'link'");
        $this->assertEquals(0, $stmt->fetchColumn());
    }

    public function test_prejmenovani_archivuje_stary_vytvori_novy(): void
    {
        mkdir($this->watchDir . '/Stary nazev');
        $this->runSync();
        rename($this->watchDir . '/Stary nazev', $this->watchDir . '/Novy nazev');
        $this->runSync();

        $stmt = $this->pdo->query("SELECT status FROM projects WHERE folder_path = 'Stary nazev'");
        $this->assertEquals('archived', $stmt->fetchColumn());

        $stmt = $this->pdo->query("SELECT status FROM projects WHERE folder_path = 'Novy nazev'");
        $this->assertEquals('active', $stmt->fetchColumn());
    }

    private function runSync(): void
    {
        // Spustit cli/sync-projects.php jako proces
        putenv("PROJECTS_WATCH_DIR=$this->watchDir");
        require 'cli/sync-projects.php';
    }
}
```

### 4.7 InvoiceApiTest

```php
class InvoiceApiTest extends TestCase
{
    public function test_dph_vypocet_21_procent(): void
    {
        $this->login();
        $response = $this->http->post('/api/invoices', ['json' => [
            'client_id' => 1,
            'invoice_number' => '2026001',
            'subtotal_cents' => 100000,  // 1000 Kč
            'vat_rate_percent' => 21,
            'issue_date' => '2026-01-01',
            'due_date' => '2026-01-15'
        ]]);
        $body = json_decode($response->getBody(), true);
        $this->assertEquals(21000, $body['vat_amount_cents']);   // 210 Kč
        $this->assertEquals(121000, $body['amount_cents']);       // 1210 Kč
    }

    public function test_dph_0_procent(): void
    {
        $this->login();
        $response = $this->http->post('/api/invoices', ['json' => [
            'client_id' => 1,
            'invoice_number' => '2026002',
            'subtotal_cents' => 100000,
            'vat_rate_percent' => 0,
            'issue_date' => '2026-01-01',
            'due_date' => '2026-01-15'
        ]]);
        $body = json_decode($response->getBody(), true);
        $this->assertEquals(0, $body['vat_amount_cents']);
        $this->assertEquals(100000, $body['amount_cents']);
    }

    public function test_cislovani_formatu_rok_seq(): void
    {
        $this->login();
        // Nastavit invoice_seq = 0, invoice_seq_year = 2026
        $this->pdo->exec("UPDATE settings SET value = '0' WHERE `key` = 'invoice_seq'");
        $this->pdo->exec("UPDATE settings SET value = '2026' WHERE `key` = 'invoice_seq_year'");

        // Vytvořit fakturu - měla by dostat 2026001
        $response = $this->http->post('/api/invoices', ['json' => [
            'client_id' => 1,
            'subtotal_cents' => 100000,
            'vat_rate_percent' => 21,
            'issue_date' => '2026-01-01',
            'due_date' => '2026-01-15'
        ]]);
        $body = json_decode($response->getBody(), true);
        $this->assertEquals('2026001', $body['invoice_number']);
    }

    public function test_smazani_faktury_smaze_platby(): void
    {
        $this->login();
        $invoice = $this->http->post('/api/invoices', ['json' => [
            'client_id' => 1,
            'invoice_number' => '2026003',
            'subtotal_cents' => 100000,
            'vat_rate_percent' => 21,
            'issue_date' => '2026-01-01',
            'due_date' => '2026-01-15'
        ]]);
        $invoiceId = json_decode($invoice->getBody(), true)['id'];

        // Přidat platbu
        $this->http->post('/api/invoice-payments', ['json' => [
            'invoice_id' => $invoiceId,
            'amount_cents' => 50000,
            'payment_date' => '2026-01-10'
        ]]);

        // Smazat fakturu
        $this->http->delete("/api/invoices/$invoiceId");

        // Platby by měly být smazány (CASCADE)
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ?');
        $stmt->execute([$invoiceId]);
        $this->assertEquals(0, $stmt->fetchColumn());
    }

    public function test_pdf_stahovani(): void
    {
        $this->login();
        $invoice = $this->http->post('/api/invoices', ['json' => [
            'client_id' => 1,
            'invoice_number' => '2026004',
            'subtotal_cents' => 100000,
            'vat_rate_percent' => 21,
            'issue_date' => '2026-01-01',
            'due_date' => '2026-01-15'
        ]]);
        $invoiceId = json_decode($invoice->getBody(), true)['id'];

        $response = $this->http->get("/api/invoices/$invoiceId/pdf");
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/pdf', $response->getHeader('Content-Type')[0]);
        $this->assertStringContainsString('faktura-2026004', $response->getHeader('Content-Disposition')[0]);
        $this->assertGreaterThan(1000, strlen($response->getBody()));  // ne-prázdné PDF
    }
}
```

---

## 5. Bezpečnostní testy - specifikace

### 5.1 CsrfTest

```php
class CsrfTest extends TestCase
{
    public function test_post_bez_csrf_tokenu_vrati_403(): void
    {
        $this->login();
        $response = $this->http->post('/api/clients', [
            'json' => ['type' => 'individual', 'first_name' => 'Jan', 'last_name' => 'Novák']
        ]);
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_post_se_spatnym_csrf_tokenem_vrati_403(): void
    {
        $this->login();
        $response = $this->http->post('/api/clients', [
            'json' => ['type' => 'individual', 'first_name' => 'Jan', 'last_name' => 'Novák'],
            'headers' => ['X-CSRF-Token' => 'spatny-token']
        ]);
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_get_bez_csrf_tokenu_projde(): void
    {
        $this->login();
        $response = $this->http->get('/api/clients');
        $this->assertEquals(200, $response->getStatusCode());
    }
}
```

### 5.2 SqlInjectionTest

```php
class SqlInjectionTest extends TestCase
{
    public function test_sql_injection_v_username(): void
    {
        $response = $this->http->post('/api/auth/login', [
            'json' => [
                'username' => "admin' OR '1'='1",
                'password' => 'cokoliv'
            ]
        ]);
        $this->assertEquals(401, $response->getStatusCode());
        // Ne 200 - injection nesmí fungovat
    }

    public function test_sql_injection_v_hledani(): void
    {
        $this->login();
        $response = $this->http->get('/api/clients?search=' . urlencode("' UNION SELECT * FROM users--"));
        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        // Nevrátí users data
        $this->assertStringNotContainsString('password_hash', json_encode($body));
    }
}
```

### 5.3 FileUploadTest

```php
class FileUploadTest extends TestCase
{
    public function test_upload_exe_vrati_422(): void
    {
        $this->login();
        $response = $this->http->post('/api/files', [
            'multipart' => [[
                'name' => 'file',
                'filename' => 'virus.exe',
                'contents' => 'MZ'  // EXE hlavička
            ]]
        ]);
        $this->assertEquals(422, $response->getStatusCode());
    }

    public function test_upload_php_vrati_422(): void
    {
        $this->login();
        $response = $this->http->post('/api/files', [
            'multipart' => [[
                'name' => 'file',
                'filename' => 'shell.php',
                'contents' => '<?php system($_GET["cmd"]);'
            ]]
        ]);
        $this->assertEquals(422, $response->getStatusCode());
    }

    public function test_upload_prilis_velky_soubor_vrati_413(): void
    {
        $this->login();
        $response = $this->http->post('/api/files', [
            'multipart' => [[
                'name' => 'file',
                'filename' => 'velky.pdf',
                'contents' => str_repeat('x', 11 * 1024 * 1024)  // 11MB
            ]]
        ]);
        $this->assertEquals(413, $response->getStatusCode());
    }

    public function test_upload_png_projde(): void
    {
        $this->login();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==');
        $response = $this->http->post('/api/files', [
            'multipart' => [[
                'name' => 'file',
                'filename' => 'test.png',
                'contents' => $png
            ]]
        ]);
        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertEquals(1, $body['is_image']);
        $this->assertNotNull($body['thumbnail_path']);
    }
}
```

### 5.4 DirectoryTraversalTest

```php
class DirectoryTraversalTest extends TestCase
{
    public function test_download_s_directory_traversal_vrati_403(): void
    {
        $this->login();
        // Vytvořit soubor
        $file = $this->http->post('/api/files', [...]);
        $fileId = json_decode($file->getBody(), true)['id'];

        // Pokus o traversal
        $response = $this->http->get("/api/files/$fileId/download?path=../../../etc/passwd");
        $this->assertEquals(403, $response->getStatusCode());
    }
}
```

### 5.5 AuthorizationTest

```php
class AuthorizationTest extends TestCase
{
    public function test_vsechny_endpointy_vyaduji_prihlaseni(): void
    {
        $endpoints = [
            ['GET', '/api/clients'],
            ['GET', '/api/projects'],
            ['GET', '/api/tasks'],
            ['GET', '/api/invoices'],
            ['GET', '/api/transactions'],
            ['GET', '/api/notes'],
            ['GET', '/api/files'],
            ['GET', '/api/settings'],
            ['GET', '/api/company-profile'],
            ['GET', '/api/users/me/preferences'],
        ];
        foreach ($endpoints as [$method, $path]) {
            $response = $this->http->request($method, $path);
            $this->assertEquals(401, $response->getStatusCode(), "Endpoint $method $path by měl vyžadovat přihlášení");
        }
    }

    public function test_public_endpointy_nevyaduji_prihlaseni(): void
    {
        $endpoints = [
            ['POST', '/api/auth/login'],
            ['POST', '/api/auth/password-hint'],
            ['POST', '/api/auth/reset-password'],
        ];
        foreach ($endpoints as [$method, $path]) {
            $response = $this->http->request($method, $path, ['json' => []]);
            $this->assertNotEquals(401, $response->getStatusCode(), "Endpoint $method $path by neměl vyžadovat přihlášení");
        }
    }
}
```

---

## 6. Frontend testy - specifikace

### 6.1 utils.test.ts

```typescript
import { describe, it, expect } from 'vitest';
import { fmtDate, fmtDateTime, fmtMoney, fmtMinutes, fmtClientName } from '@/lib/utils';

describe('fmtDate', () => {
  it('formátuje datum bez úvodních nul', () => {
    expect(fmtDate('2026-03-26T14:30:00Z')).toBe('26.3.2026');
  });
  it('formátuje datum s úvodními nulami', () => {
    expect(fmtDate('2026-01-05T00:00:00Z')).toBe('5.1.2026');
  });
});

describe('fmtMoney', () => {
  it('formátuje částku bez desetinných míst', () => {
    expect(fmtMoney(150000)).toBe('1 500 Kč');
  });
  it('zaokrouhluje haléře', () => {
    expect(fmtMoney(150045)).toBe('1 500 Kč');
  });
  it('zobrazuje nulu', () => {
    expect(fmtMoney(0)).toBe('0 Kč');
  });
});

describe('fmtMinutes', () => {
  it('zobrazuje hodiny a minuty', () => {
    expect(fmtMinutes(255)).toBe('4h 15m');
  });
  it('zobrazuje jen hodiny', () => {
    expect(fmtMinutes(240)).toBe('4h');
  });
  it('zobrazuje jen minuty', () => {
    expect(fmtMinutes(15)).toBe('15m');
  });
  it('zobrazuje nulu', () => {
    expect(fmtMinutes(0)).toBe('0m');
  });
});

describe('fmtClientName', () => {
  it('osoba - first_name last_name', () => {
    expect(fmtClientName('Jan', 'Novák')).toBe('Jan Novák');
  });
});
```

### 6.2 LoginPage.test.tsx

```typescript
import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { LoginPage } from '@/pages/LoginPage';

describe('LoginPage', () => {
  it('zobrazí přepínač tématu', () => {
    render(<LoginPage />);
    expect(screen.getByRole('button', { name: /theme|téma|sun|moon/i })).toBeInTheDocument();
  });

  it('zobrazí odkaz na reset hesla', () => {
    render(<LoginPage />);
    expect(screen.getByText(/zapomněli jste heslo/i)).toBeInTheDocument();
  });

  it('přepne na reset formulář po kliku na odkaz', () => {
    render(<LoginPage />);
    fireEvent.click(screen.getByText(/zapomněli jste heslo/i));
    expect(screen.getByLabelText(/uživatelské jméno/i)).toBeInTheDocument();
    expect(screen.getByText(/nápověda/i)).toBeInTheDocument();
  });

  it('zobrazí hint po zadání username', async () => {
    render(<LoginPage />);
    fireEvent.click(screen.getByText(/zapomněli jste heslo/i));
    fireEvent.change(screen.getByLabelText(/uživatelské jméno/i), { target: { value: 'admin' } });
    fireEvent.click(screen.getByText(/zobrazit nápovědu/i));
    // MSW mock vrátí hint
    expect(await screen.findByText(/Jméno mého prvního psa/i)).toBeInTheDocument();
  });
});
```

### 6.3 Sidebar.test.tsx

```typescript
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Sidebar } from '@/components/layout/Sidebar';

describe('Sidebar', () => {
  it('zobrazí 8 navigačních položek', () => {
    render(<Sidebar />);
    expect(screen.getByText('Dashboard')).toBeInTheDocument();
    expect(screen.getByText('Klienti')).toBeInTheDocument();
    expect(screen.getByText('Projekty')).toBeInTheDocument();
    expect(screen.getByText('Úkoly')).toBeInTheDocument();
    expect(screen.getByText('Finance')).toBeInTheDocument();
    expect(screen.getByText('Poznámky')).toBeInTheDocument();
    expect(screen.getByText('Soubory')).toBeInTheDocument();
    expect(screen.getByText('Nastavení')).toBeInTheDocument();
  });

  it('po sbalení zobrazí jen ikony', () => {
    render(<Sidebar collapsed={true} />);
    expect(screen.queryByText('Dashboard')).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: /dashboard/i })).toBeInTheDocument();
  });
});
```

---

## 7. Spuštění testů

### 7.1 Backend

```bash
# Všechny testy
./vendor/bin/phpunit

# Konkrétní test
./vendor/bin/phpunit tests/Integration/LoginApiTest.php

# S pokrytím
./vendor/bin/phpunit --coverage-html coverage/

# Jen bezpečnostní testy
./vendor/bin/phpunit tests/Security/
```

### 7.2 Frontend

```bash
# Všechny testy
npx vitest

# Watch mode
npx vitest watch

# S pokrytím
npx vitest --coverage

# Konkrétní soubor
npx vitest tests/lib/utils.test.ts
```

### 7.3 CI (volitelně)

```bash
# Backend + frontend najednou
./vendor/bin/phpunit && npx vitest run
```

---

## 8. Pravidla

### 8.1 TDD cyklus

1. **Red** - napiš test, který selže (funkce neexistuje)
2. **Green** - napiš minimální kód, který test splní
3. **Refactor** - uprav kód, test musí stále projít

### 8.2 Test data

- Test DB: `devapppro_test` (oddělená)
- Seed: `database/seed_test.sql` (admin user, settings, company_profile)
- Každý test začíná s čistou DB (setUp načte schema + seed)
- Žádné sdílení stavu mezi testy

### 8.3 Pokrytí

- **Kritické cesty:** 100% (login, CRUD, faktury, sync)
- **Bezpečnost:** 100% (CSRF, SQL injection, XSS, upload, traversal, auth)
- **Modularita:** měřeno PHPStan (level 6+) a ESLint
- **Rychlost:** měřeno Lighthouse + EXPLAIN (ne testy)

### 8.4 Pojmenování

- Backend: `*Test.php` (PascalCase)
- Frontend: `*.test.ts(x)` (camelCase)
- Test metody: `test_popis_ve_cestine()` (backend), `it('popis česky')` (frontend)

---

## 9. Co se netestuje automatizovaně

- **UX** - vzhled, barvy, rozložení (manuálně)
- **Apache konfigurace** - bind 127.0.0.1, .htaccess (manuálně)
- **PHP konfigurace** - php.ini hodnoty (manuálně)
- **Cron** - skutečné spuštění (manuálně, ale logika se testuje)
- **PDF vzhled** - obsah se testuje, vzhled manuálně
- **Téma vzhled** - funkčnost ano, vzhled manuálně
- **Lighthouse skóre** - měřeno nástrojem
- **PHPStan** - měřeno nástrojem

Tyto položky zůstávají v `09-checklisty.md` pro manuální odškrtávání.
