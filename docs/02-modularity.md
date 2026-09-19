# 02 - Modularita

Modularita je priorita číslo 2. Aplikace je rozdělena do nezávislých vrstev a modulů, které lze samostatně měnit, testovat a nahrazovat.

---

## 1. Vrstvená architektura

```
┌──────────────────────────────────────────────┐
│  Frontend (React SPA)                         │
│  ├── pages/           - stránky (Dashboard,    │
│  │                      Clients, Projects...) │
│  ├── components/      - shadcn/ui komponenty   │
│  ├── hooks/           - vlastní React hooks    │
│  ├── lib/             - API klient, utils      │
│  └── types/           - TypeScript typy        │
├──────────────────────────────────────────────┤
│  API vrstva (PHP)                             │
│  ├── api/             - vstupní body endpointů │
│  └── src/Controllers/ - ApiController třídy    │
├──────────────────────────────────────────────┤
│  Doménová vrstva (PHP)                        │
│  └── src/Repositories/ - Repository třídy      │
│                        (DB přístup, dotazy)    │
├──────────────────────────────────────────────┤
│  Infrastruktura (PHP)                         │
│  ├── src/Core/        - ApiController base,    │
│  │                     Repository base         │
│  ├── config/          - konfigurace            │
│  └── src/helpers.php  - sdílené funkce         │
├──────────────────────────────────────────────┤
│  Databáze (MariaDB)                           │
│  └── database/        - schema, migrace        │
└──────────────────────────────────────────────┘
```

### Pravidla závislostí

- **Frontend → API:** React komunikuje s backendem pouze přes REST API (fetch)
- **API → Doména:** Controllers volají Repositories, ne přímé SQL
- **Doména → Infrastruktura:** Repositories používají PDO (přijímají z config)
- **Žádné skokové závislosti:** Frontend nikdy nevolá Repository, Controller nevolá React

---

## 2. Backend moduly (PHP)

### 2.1 PSR-4 autoloading

```
src/
├── Core/
│   ├── ApiController.php      - abstraktní base controller
│   └── Repository.php         - abstraktní base repository
├── Controllers/
│   ├── AuthApiController.php
│   ├── ClientApiController.php
│   ├── ProjectApiController.php
│   ├── TaskApiController.php
│   ├── InvoiceApiController.php
│   ├── InvoicePaymentApiController.php
│   ├── TransactionApiController.php
│   ├── NoteApiController.php
│   └── FileApiController.php
├── Repositories/
│   ├── ClientRepository.php
│   ├── ProjectRepository.php
│   ├── TaskRepository.php
│   ├── InvoiceRepository.php
│   ├── InvoicePaymentRepository.php
│   ├── TransactionRepository.php
│   ├── NoteRepository.php
│   └── FileRepository.php
├── Auth.php                   - autentizace, session, rate limiting
└── helpers.php                - e(), base_url(), money_int(), fmt_date()
```

```json
// composer.json
{
    "autoload": {
        "psr-4": {
            "DevAppPro\\": "src/"
        }
    }
}
```

### 2.2 ApiController (base)

```php
abstract class ApiController
{
    protected PDO $pdo;
    protected array $user;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->user = current_user() ?? [];
    }

    // Společné metody pro všechny controllery
    abstract public function handle(): void;

    protected function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    protected function requireAuth(): void
    {
        if (empty($this->user['id'])) {
            $this->json(['error' => 'Neautorizováno.'], 401);
        }
    }

    protected function requireCsrf(): void
    {
        if (!csrf_verify()) {
            $this->json(['error' => 'Neplatný CSRF token.'], 403);
        }
    }

    protected function input(): array
    {
        $raw = file_get_contents('php://input');
        return json_decode($raw, true) ?? [];
    }

    protected function validate(array $data, array $rules): array
    {
        // Vrátí validovaná data nebo odešle 422
    }
}
```

### 2.3 Repository (base)

```php
abstract class Repository
{
    protected PDO $pdo;
    protected string $table;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function all(int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->table} ORDER BY id DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll();
    }

    public function count(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$this->table}")->fetchColumn();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = ?");
        return $stmt->execute([$id]);
    }
}
```

### 2.4 Konkrétní Repository (příklad)

```php
class ClientRepository extends Repository
{
    protected string $table = 'clients';

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO clients (name, email, phone, note)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['name'],
            $data['email'] ?? null,
            $data['phone'] ?? null,
            $data['note'] ?? null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE clients SET name = ?, email = ?, phone = ?, note = ?
             WHERE id = ?'
        );
        return $stmt->execute([
            $data['name'],
            $data['email'] ?? null,
            $data['phone'] ?? null,
            $data['note'] ?? null,
            $id,
        ]);
    }

    public function search(string $query): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM clients WHERE name LIKE ? OR email LIKE ?
             ORDER BY name LIMIT 50'
        );
        $stmt->execute(["%$query%", "%$query%"]);
        return $stmt->fetchAll();
    }
}
```

### 2.5 API endpoint (vstupní bod)

```php
// api/clients.php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

$controller = new DevAppPro\Controllers\ClientApiController(db());
$controller->handle();
```

```php
// src/Controllers/ClientApiController.php
class ClientApiController extends ApiController
{
    public function handle(): void
    {
        $this->requireAuth();
        $method = $_SERVER['REQUEST_METHOD'];

        switch ($method) {
            case 'GET':    $this->index();   break;
            case 'POST':   $this->store();   break;
            case 'PUT':    $this->update();  break;
            case 'DELETE': $this->destroy(); break;
            default:       $this->json(['error' => 'Nepodporovaná metoda.'], 405);
        }
    }

    private function index(): void { /* ... */ }
    private function store(): void
    {
        $this->requireCsrf();
        $data = $this->input();
        // validace + create
    }
    // ...
}
```

---

## 3. Frontend moduly (React)

### 3.1 Struktura

```
frontend/
├── src/
│   ├── main.tsx              - vstupní bod
│   ├── App.tsx              - router + layout shell
│   ├── pages/
│   │   ├── DashboardPage.tsx
│   │   ├── ClientsPage.tsx
│   │   ├── ClientDetailPage.tsx
│   │   ├── ProjectsPage.tsx
│   │   ├── ProjectDetailPage.tsx
│   │   ├── TasksPage.tsx
│   │   ├── FinancePage.tsx
│   │   ├── NotesPage.tsx
│   │   ├── FilesPage.tsx
│   │   ├── SettingsPage.tsx
│   │   ├── LoginPage.tsx
│   │   └── NotFoundPage.tsx
│   ├── components/
│   │   ├── ui/               - shadcn/ui komponenty (button, card, dialog...)
│   │   ├── layout/
│   │   │   ├── AppShell.tsx  - sidebar + topbar + content
│   │   │   ├── Sidebar.tsx
│   │   │   └── Topbar.tsx
│   │   ├── clients/          - specifické komponenty pro klienty
│   │   ├── projects/         - specifické pro projekty
│   │   ├── tasks/            - specifické pro úkoly
│   │   ├── finance/          - specifické pro finance
│   │   ├── notes/            - specifické pro poznámky
│   │   ├── files/            - specifické pro soubory
│   │   └── shared/          - sdílené (DataTable, EmptyState, ErrorState)
│   ├── hooks/
│   │   ├── useAuth.ts        - autentizace, session
│   │   ├── useApi.ts         - fetch wrapper s CSRF
│   │   ├── useClients.ts     - CRUD pro klienty
│   │   ├── useProjects.ts
│   │   ├── useProjectActions.ts  - archive, restore
│   │   ├── useTasks.ts
│   │   ├── useFinance.ts
│   │   ├── useNotes.ts
│   │   └── useFiles.ts
│   ├── lib/
│   │   ├── api.ts            - API klient (fetch wrapper)
│   │   ├── utils.ts          - cn(), formátování data, měny
│   │   └── constants.ts      - statusy, priority, barvy
│   ├── types/
│   │   ├── client.ts
│   │   ├── project.ts
│   │   ├── task.ts
│   │   ├── invoice.ts
│   │   ├── note.ts
│   │   ├── file.ts
│   │   └── api.ts
│   └── styles/
│       └── globals.css       - Tailwind directives + theme tokens
├── public/
│   ├── fonts/                - Inter, JetBrains Mono (WOFF2)
│   └── favicon.ico
├── package.json
├── vite.config.ts
├── tailwind.config.ts
├── tsconfig.json
└── components.json           - shadcn config
```

### 3.2 Moduly podle domény

Každá doména (klienti, projekty, úkoly, finance) má:
- **Page** komponentu (route)
- **hooks** pro data fetching (useClients, useProjects...)
- **types** pro TypeScript
- **komponenty** specifické pro danou doménu

### 3.3 Sdílené komponenty

- `DataTable` - generická tabulka s řazením, filtrem, paginací
- `EmptyState` - prázdný stav s ikonou a textem
- `ErrorState` - chybový stav
- `LoadingSpinner` - načítací stav
- `ConfirmDialog` - potvrzovací dialog
- `PageHeader` - hlavička stránky (titulek + akce)

---

## 4. Konfigurace modulů

### 4.1 Oddělení konfigurace

```
config/
├── config.php       - APP_NAME, APP_VERSION, APP_ENV, APP_DEBUG
├── database.php     - DB připojení (host, name, user, pass)
└── session.php      - session parametry (lifetime, cookie params)
```

- **Žádné hardcoded hodnoty** v kódu
- Konfigurace načtena v `bootstrap.php`
- Databázové připojení vytvořeno jednou, předáno do Repositories

### 4.2 Bootstrap

```php
// bootstrap.php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/src/helpers.php';

// Session
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'path' => '/',
]);
session_start();
```

---

## 5. Pravidla modularity

1. **Jedna odpovědnost** - každá třída/komponenta dělá jednu věc
2. **Vrstvy nezávislé** - Repository neví o Controlleru, Controller neví o React
3. **Dependency injection** - PDO předáno do Repository, Repository do Controlleru
4. **Žádné globální stavy** - kromě session a config konstant
5. **Interface kontrakt** - Repository má jasný interface (find, all, create, update, delete)
6. **Nepřidávat modul bez dokumentace** - každý modul má jasný účel
7. **Testovatelnost** - každá vrstva lze testovat samostatně (mock PDO, mock API)

### 5.1 Velikost souborů

- **Maximum 500 řádků** na soubor (PHP, TypeScript, TSX)
- 520 řádků je tolerováno (stane se), 530 už ne
- Pokud soubor roste nad limit → **refaktorovat a rozdělit**
- Rozdělení podle přirozených hranic:
  - Velký Controller → rozdělit na více menších Controllerů nebo traity
  - Velká React komponenta → rozdělit na podkomponenty
  - Velký Repository → rozdělit na čisté metody + traity pro specifické dotazy

### 5.2 Zanoření

- **Maximum 4 úrovní zanoření logiky** (if, for, while, try/catch, callback)
- 5 úrovní = refaktorovat (extrahovat do funkce/metody)
- **Platí pro logiku, ne JSX** - JSX zanoření je prezentace, ne logika
- Příklad měření (logika):
  ```
  function () {           // 0
    if (x) {              // 1
      for (...) {         // 2
        if (y) {          // 3
          if (z) {        // 4 - OK
            if (w) {}     // 5 - REFRAKTOROVAT
          }
        }
      }
    }
  }
  ```
- JSX zanoření (OK, nepočítá se):
  ```tsx
  <div>                    {/* prezentace, ne logika */}
    <Card>
      <CardContent>
        <div>
          <p>Text</p>
        </div>
      </CardContent>
    </Card>
  </div>
  ```

### 5.3 Žádné duplikace funkcí

- **Funkce jsou vždy sdílené** - žádné kopírování logiky mezi soubory
- **Rule of three** - extrahovat do sdílené funkce až při **3. výskytu** stejné logiky
  - 1. výskyt: napsat
  - 2. výskyt: tolerovat (možná náhoda)
  - 3. výskyt: extrahovat do sdílené funkce
- Před předčasnou abstrakcí radši ponechat oddělené kopie
- Sdílené funkce umístěny v:
  - **Backend:** `src/helpers.php` nebo `src/Core/` třídy
  - **Frontend:** `src/lib/utils.ts` nebo `src/hooks/`
- Příklady sdílených funkcí:
  - `e()` - HTML escape
  - `money_int()` - formátování měny
  - `fmt_date()` - formátování data
  - `cn()` - merge Tailwind tříd
  - `api.get()`, `api.post()` - API klient
- **Pravidlo:** před napsáním nové funkce zkontrolovat, zda už neexistuje

### 5.4 Směr závislostí (Dependency Rule)

Závislosti jdou **jen jedním směrem** - od volajícího k volanému. Nikdy zpět.

```
React → API → Controller → Repository → DB
```

- Repository **nikdy** nevolá Controller
- Controller **nikdy** neimportuje React komponentu
- Žádné kruhové závislosti (A → B → A)
- Nižší vrstva neví o vyšší vrstvě

### 5.5 High cohesion, low coupling

- **Cohesion** - věci co spolu souvisí, jsou spolu v jednom souboru/modulu
- **Coupling** - moduly jsou propojené minimálně, přes jasný interface
- `ClientRepository` obsahuje jen SQL pro klienty, ne pro projekty
- Změna v `ClientRepository` nevyžaduje změnu v `ProjectRepository`

### 5.6 Composition over inheritance

- Preferovat **kompozici** před dědičností
- Controller přijímá Repository jako závislost, nedědí z ní
- React komponenty se skládají, ne dědí
- Dědičnost jen pro `BaseRepository`, `BaseController` (jediná úroveň)
- Žádné hluboké hierarchie (A → B → C → D)

### 5.7 Žádné magic numbers a stringy

- Konstanty a enumy, ne hardcoded hodnoty
- `$status = 'active'` → `ProjectStatus::ACTIVE->value`
- `LIMIT 50` → `DEFAULT_PAGE_SIZE`
- Snadnější údržba, žádné překlepy
- Všechny konstanty na jednom místě (`config/config.php` nebo enum třída)

### 5.8 Fail fast

- Chybu nahlásit co nejdřív, ne potichu ignorovat
- Neplatný vstup → výjimka nebo 422 hned
- Ne `return null` a doufat, že si s tím volající poradí
- Explicitní chyby > skryté bugy
- Validace na vstupu každé vrstvy

### 5.9 Explicit over implicit

- Radši explicitní parametr než magické chování
- Funkce nedělá věci, které z názvu nejsou zřejmé
- `createClient($data)` nevolá `sendEmail()` tajně
- Vedlejší efekty dokumentovány nebo odděleny
- Žádné skryté globální stavy

### 5.10 Naming konvence

| Typ | Konvence | Příklad |
|---|---|---|
| PHP třída | PascalCase | `ClientRepository` |
| PHP metoda | camelCase | `findById` |
| PHP property | camelCase | `$pdo` |
| PHP konstanta | UPPER_SNAKE | `DEFAULT_PAGE_SIZE` |
| TS typ/interface | PascalCase | `Client` |
| TS funkce | camelCase | `useClients` |
| TS komponenta | PascalCase | `ClientForm` |
| TS konstanta | UPPER_SNAKE | `API_BASE_URL` |
| Soubor PHP | PascalCase | `ClientRepository.php` |
| Soubor TS komponenta | PascalCase | `ClientForm.tsx` |
| Soubor TS util | kebab-case | `api-client.ts` |

### 5.11 Separation of concerns

- **Validace** - vlastní vrstva, ne v Controlleru rozptýlená
- **Business logika** - v Repository nebo service, ne v Controlleru
- **Data access** - jen v Repository, nikde jinde SQL
- **Presentation** - jen v React, ne v PHP
- **Routing** - jen v React Router, ne v PHP
- **Auth** - middleware vrstva, ne v každém Controlleru

### 5.12 Pure functions kde možno

- Funkce bez side efektů - stejný vstup = stejný výstup
- `money_int(15000)` vždy vrátí `"150 Kč"`
- `fmt_date('2026-09-11')` vždy vrátí `"11. 9. 2026"`
- Snadné testovat, žádné překvapení
- Side efekty (DB, API, I/O) izolovány do specifických vrstev

### 5.13 Stable dependencies

- Záviset na stabilních věcech (interfacely, konstanty), ne na nestabilních (konkrétní implementace)
- Controller závisí na Repository interface, ne na konkrétní `ClientRepository`
- Umožňuje mockování pro testy
- Vrstva s méně změnami by neměla záviset na vrstvě s více změnami
